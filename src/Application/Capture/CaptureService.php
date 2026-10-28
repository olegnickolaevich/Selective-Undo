<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ChangeEvent;
use SelectiveUndo\Domain\Change\ChangesetKind;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\CaptureSink;
use SelectiveUndo\Domain\Contracts\LockService;
use SelectiveUndo\Domain\Contracts\WallClock;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;
use SelectiveUndo\Infrastructure\Settings\Settings;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalRepository;
use SelectiveUndo\Infrastructure\WordPress\CaptureFrame;
use SelectiveUndo\Infrastructure\WordPress\PostFieldsAdapter;
use SelectiveUndo\Infrastructure\WordPress\PostRow;

/**
 * Writes captured changes to the journal. Every write is a short autocommit
 * statement on the WordPress connection so that nothing is lost between requests.
 * Post fields come from capture frames (PostChangeObserver); other adapters report
 * their changes through the CaptureSink interface.
 *
 * @phpstan-import-type ChangeRow from JournalRepository
 */
final class CaptureService implements CaptureSink
{
    /** Idle window after which an autosave session is sealed. */
    public const AUTOSAVE_WINDOW_SECONDS = 600;

    /** Positions left free for the rows of a concurrent request of the same save. */
    private const JOINED_SEQUENCE_GAP = 1000;

    public function __construct(
        private readonly \wpdb $db,
        private readonly JournalRepository $journal,
        private readonly BlobStore $blobs,
        private readonly RequestContext $request,
        private readonly CaptureState $state,
        private readonly GapRecorder $gaps,
        private readonly Settings $settings,
        private readonly EventLog $log,
        private readonly WallClock $clock,
        private readonly RevisionImporter $revisions,
        private readonly LockService $locks,
        private readonly CaptureCircuitBreaker $breaker,
    ) {
    }

    /** Writes made by a restore's own post-processing are not new history. */
    public function isActive(): bool
    {
        return $this->state->isActive() && !$this->request->isRestoring();
    }

    public function record(AdapterInterface $adapter, ObjectRef $object, array $changes, CaptureQuality $quality): void
    {
        if (!$this->isActive() || $changes === []) {
            return;
        }

        $this->quietly(function () use ($adapter, $object, $changes, $quality): void {
            try {
                $this->revisions->ensureImported($object);
                $this->checkContinuity($object, $changes);
                $this->journal->insertChanges(
                    $this->buildRows($this->currentChangesetId($object), $this->request->reserveSequence(), $object, $changes, $quality, $adapter)
                );
            } catch (\Throwable $e) {
                // Called from WordPress hooks: a journal error must never break the save.
                $this->log->exception('capture_failed', $e, $object->id);
                $this->gaps->object('capture_failed', $object);
            }
        });
    }

    public function recordPostUpdate(CaptureFrame $frame, PostRow $after, CaptureQuality $quality): void
    {
        if (!$this->state->isActive()) {
            return;
        }

        $before = $frame->before;
        $object = new ObjectRef('post', $frame->postId, $after->postType);

        // The first real save of an auto-draft is a creation, not an undoable edit.
        if ($before->postStatus === 'auto-draft') {
            if ($after->postStatus !== 'auto-draft') {
                $this->recordEvent(ChangeEvent::Created, $object, $frame->sequence);
            }

            return;
        }

        $changed = [];

        foreach (PostRow::TRACKED_FIELDS as $field) {
            $b = $before->field($field);
            $a = $after->field($field);

            if (!$b->equals($a)) {
                $changed[$field] = [$b, $a];
            }
        }

        if ($before->postDateGmt === '0000-00-00 00:00:00' && $after->postDateGmt === '0000-00-00 00:00:00') {
            // A draft without a chosen date: WordPress moves post_date to "now" on every save.
            unset($changed['post_date']);
        }

        if ($before->postStatus !== $after->postStatus && array_intersect([$before->postStatus, $after->postStatus], ['trash', 'auto-draft']) !== []) {
            // Moving to and from the trash is an event (and WordPress renames the slug
            // meanwhile); the trash itself is how WordPress undoes it.
            unset($changed['post_status'], $changed['post_name']);
        }

        if ($changed === []) {
            return;
        }

        $this->quietly(function () use ($object, $changed, $quality, $frame): void {
            $this->revisions->ensureImported($object);
            $actor = get_current_user_id();

            if ($this->request->source() === Source::Autosave && $this->settings->coalesceAutosaves() && $actor > 0) {
                $this->coalesceAutosave($actor, $object, $changed, $quality);

                return;
            }

            if ($actor > 0) {
                $this->journal->sealAutosaveSessions($actor, $object);
            }

            $this->checkContinuity($object, $changed);
            $changesetId = $this->currentChangesetId($object);
            $this->journal->insertChanges($this->buildRows($changesetId, $frame->sequence, $object, $changed, $quality));
        });
    }

    public function recordEvent(ChangeEvent $event, ObjectRef $object, ?int $sequence = null): void
    {
        if (!$this->state->isActive()) {
            return;
        }

        $this->quietly(function () use ($event, $object, $sequence): void {
            $this->revisions->ensureImported($object);
            $this->journal->insertChanges([[
                'changeset_id' => $this->currentChangesetId($object),
                'sequence_no' => $sequence ?? $this->request->reserveSequence(),
                'event' => $event->value,
                'object' => $object,
                'adapter_key' => PostFieldsAdapter::KEY,
                'adapter_version' => PostFieldsAdapter::VERSION,
                'field_key' => '',
                'before_blob_id' => null,
                'after_blob_id' => null,
                'before_hash' => null,
                'after_hash' => null,
                'quality' => CaptureQuality::Verified->value,
                'restorable' => false,
                'reason_code' => 'informational_event',
            ]]);
        });
    }

    /**
     * Object-level signal about incomplete history.
     *
     * @param array<string, scalar> $details
     */
    public function recordGap(string $reason, ObjectRef $object, array $details = []): void
    {
        $this->gaps->object($reason, $object, $details);
    }

    public function reportFailure(int $postId, \Throwable $e): void
    {
        $this->log->exception('capture_failed', $e, $postId);
        $this->gaps->object('capture_failed', new ObjectRef('post', $postId));
    }

    /**
     * Seals changesets opened by this request (autosave sessions stay open).
     */
    public function sealRequestChangesets(): void
    {
        foreach ($this->request->changesets() as $key => $id) {
            if (str_starts_with($key, 'autosave:')) {
                continue;
            }

            $this->quietly(fn () => $this->journal->seal($id));
            $this->request->forgetChangeset($key);
        }
    }

    public function currentChangesetId(?ObjectRef $object = null): int
    {
        $operation = $this->request->operation();
        $key = $operation === null ? 'edit' : 'operation:' . $operation['id'];
        $id = $this->request->changesetId($key);

        if ($id !== null) {
            return $id;
        }

        $actor = get_current_user_id();
        $token = $operation === null && $actor > 0 ? $this->request->saveToken() : null;

        if ($token !== null) {
            return $this->changesetForSave($key, $actor, $token);
        }

        if ($operation === null && $object !== null && $actor > 0 && $this->request->isMetaBoxSave()) {
            // One click on Save is two requests (REST, then meta boxes): one change set.
            $joined = $this->journal->findRecentChangeset($actor, $object, Source::BlockEditor->value, $this->clock->utcMysql(-120));

            if ($joined !== null) {
                $this->journal->reopen($joined);
                $this->request->continueSequenceAfter($this->journal->nextSequence($joined) - 1);
                $this->request->rememberChangeset($key, $joined);

                return $joined;
            }
        }

        $created = $this->journal->createChangeset([
            'kind' => $operation === null ? ChangesetKind::Edit : ChangesetKind::Operation,
            'source' => $this->request->source()->value,
            'grouping' => $operation === null ? 'request' : 'operation',
            'actor' => get_current_user_id() ?: null,
            'request_uuid' => $this->request->requestUuid(),
            'operation_id' => $operation['id'] ?? null,
        ]);
        $this->request->rememberChangeset($key, $created['id']);

        return $created['id'];
    }

    /**
     * The requests of one click on Save in the block editor share its token and one
     * change set. They may run at the same time (plugins save their fields through
     * their own endpoints while the post is being saved), so the change set is found
     * or created under a lock, and the token is kept as the change set's request UUID.
     */
    private function changesetForSave(string $key, int $actor, string $token): int
    {
        $lock = 'save_' . md5($token);
        $locked = $this->locks->acquire($lock, 5);

        try {
            $joined = $this->journal->findChangesetByRequest($actor, $token, $this->clock->utcMysql(-120));

            if ($joined !== null) {
                $this->journal->reopen($joined);
                // Another request of this save may still be writing: number after a gap
                // so that the rows of the two requests never take the same position.
                $this->request->continueSequenceAfter($this->journal->nextSequence($joined) + self::JOINED_SEQUENCE_GAP);
                $this->request->rememberChangeset($key, $joined);

                return $joined;
            }

            $created = $this->journal->createChangeset([
                'kind' => ChangesetKind::Edit,
                'source' => $this->request->source()->value,
                'grouping' => 'request',
                'actor' => $actor,
                'request_uuid' => $token,
                'operation_id' => null,
            ]);
            $this->request->rememberChangeset($key, $created['id']);

            return $created['id'];
        } finally {
            if ($locked) {
                $this->locks->release($lock);
            }
        }
    }

    /**
     * Draft autosaves by the author update the post itself. They are merged into
     * one open "session" changeset per user and post instead of flooding history.
     *
     * @param array<string, array{0: FieldValue, 1: FieldValue}> $changed
     */
    private function coalesceAutosave(int $actor, ObjectRef $object, array $changed, CaptureQuality $quality): void
    {
        $sessionId = $this->journal->findOpenAutosaveChangeset(
            $actor,
            $object,
            $this->clock->utcMysql(-self::AUTOSAVE_WINDOW_SECONDS)
        );

        if ($sessionId !== null) {
            $existing = [];

            foreach ($changed as $field => [$before]) {
                $row = $this->journal->findFieldChange($sessionId, $object, $field);

                if ($row !== null && $row['after_hash'] !== $before->hash()) {
                    // The field changed outside this session: start a new one.
                    $this->journal->seal($sessionId);
                    $sessionId = null;
                    break;
                }

                $existing[$field] = $row;
            }
        }

        if ($sessionId === null) {
            $this->checkContinuity($object, $changed);
            $created = $this->journal->createChangeset([
                'kind' => ChangesetKind::Autosave,
                'source' => Source::Autosave->value,
                'grouping' => 'session',
                'actor' => $actor,
                'request_uuid' => $this->request->requestUuid(),
            ]);
            $this->journal->insertChanges($this->buildRows($created['id'], 1, $object, $changed, $quality));

            return;
        }

        $append = [];

        foreach ($changed as $field => [$before, $after]) {
            $row = $existing[$field] ?? null;

            if ($row === null) {
                $append[$field] = [$before, $after];
                continue;
            }

            if ($row['before_hash'] === $after->hash()) {
                $this->journal->deleteChange($row['id']); // net zero within the session
                continue;
            }

            [$blobId, $restorable, $reason] = $this->storeValues([$after])[0];
            $this->journal->updateChangeAfter($row['id'], $blobId, $after->hash(), $restorable, $reason);
        }

        if ($append !== []) {
            $this->journal->insertChanges(
                $this->buildRows($sessionId, $this->journal->nextSequence($sessionId), $object, $append, $quality)
            );
        }

        $this->journal->touchChangeset($sessionId);
    }

    /**
     * A recorded "after" that differs from the new "before" means the field was
     * changed outside WordPress APIs (or before a capture gap).
     *
     * @param array<string, array{0: FieldValue, 1: FieldValue}> $changed
     */
    private function checkContinuity(ObjectRef $object, array $changed): void
    {
        $latest = $this->journal->latestAfterHashes($object, array_keys($changed));

        foreach ($changed as $field => [$before]) {
            if (isset($latest[$field]) && $latest[$field] !== $before->hash()) {
                $this->gaps->object('external_write_detected', $object, ['field' => $field]);
                $this->log->log('warning', 'external_write_detected', ['field' => $field], null, $object->type, $object->id);
            }
        }
    }

    /**
     * @param array<string, array{0: FieldValue, 1: FieldValue}> $changed
     *
     * @return list<ChangeRow>
     */
    private function buildRows(int $changesetId, int $sequence, ObjectRef $object, array $changed, CaptureQuality $quality, ?AdapterInterface $adapter = null): array
    {
        $rows = [];
        $stored = $this->storeValues(array_merge(...array_map(static fn (array $pair): array => [$pair[0], $pair[1]], array_values($changed))));

        foreach ($changed as $field => [$before, $after]) {
            [$beforeBlob, $beforeOk, $beforeReason] = array_shift($stored) ?? [null, false, 'payload_not_stored'];
            [$afterBlob, $afterOk, $afterReason] = array_shift($stored) ?? [null, false, 'payload_not_stored'];
            $restorable = $beforeOk && $afterOk && $quality !== CaptureQuality::Ambiguous;
            $reason = $beforeReason ?? $afterReason ?? ($quality === CaptureQuality::Ambiguous ? 'capture_ambiguous' : null);

            if (!$beforeOk || !$afterOk) {
                $this->gaps->object('payload_not_stored', $object, ['field' => $field]);
            }

            $rows[] = [
                'changeset_id' => $changesetId,
                'sequence_no' => $sequence,
                'event' => ChangeEvent::Update->value,
                'object' => $object,
                'adapter_key' => $adapter?->key() ?? PostFieldsAdapter::KEY,
                'adapter_version' => $adapter?->version() ?? PostFieldsAdapter::VERSION,
                'field_key' => $field,
                'before_blob_id' => $beforeBlob,
                'after_blob_id' => $afterBlob,
                'before_hash' => $before->hash(),
                'after_hash' => $after->hash(),
                'quality' => $quality->value,
                'restorable' => $restorable,
                'reason_code' => $restorable ? null : $reason,
                // Saved after "Undo in the editor": link the value to the change it undoes.
                'reverts_change_id' => $this->journal->pickRevertTarget($object, $field, $this->request->revertIntent($object->id)),
            ];
        }

        return $rows;
    }

    /**
     * @param list<FieldValue> $values
     *
     * @return list<array{0: int|null, 1: bool, 2: string|null}> blob id, stored, reason
     */
    private function storeValues(array $values): array
    {
        return array_map(
            static fn (int|InvalidPayload $r): array => $r instanceof InvalidPayload
                ? [null, false, $r->reasonCode === 'payload_too_large' ? 'payload_not_stored' : $r->reasonCode]
                : [$r, true, null],
            $this->blobs->putMany($values)
        );
    }

    /**
     * Journal errors must never break the post save and must not print database
     * errors on screen: they are converted into a diagnostic event and a gap.
     */
    private function quietly(callable $fn): void
    {
        $suppress = $this->db->suppress_errors(true);
        $started = $this->breaker->shouldSample() ? microtime(true) : null;

        try {
            $fn();
        } catch (\Throwable $e) {
            // Failures pause recording when they repeat (I-14); the caller records the gap.
            $this->breaker->failure();

            throw $e;
        } finally {
            $this->db->suppress_errors($suppress);
        }

        $this->breaker->success($started === null ? null : (microtime(true) - $started) * 1000);
    }
}
