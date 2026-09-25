<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ChangeEvent;
use SelectiveUndo\Domain\Change\ChangesetKind;
use SelectiveUndo\Domain\Change\ObjectRef;
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
 */
final class CaptureService
{
    /** Idle window after which an autosave session is sealed. */
    public const AUTOSAVE_WINDOW_SECONDS = 600;

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
    ) {
    }

    public function isActive(): bool
    {
        return $this->state->isActive();
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

        if ($changed === []) {
            return;
        }

        $this->quietly(function () use ($object, $changed, $quality, $frame): void {
            $actor = get_current_user_id();

            if ($this->request->source() === Source::Autosave && $this->settings->coalesceAutosaves() && $actor > 0) {
                $this->coalesceAutosave($actor, $object, $changed, $quality);

                return;
            }

            if ($actor > 0) {
                $this->journal->sealAutosaveSessions($actor, $object);
            }

            $this->checkContinuity($object, $changed);
            $changesetId = $this->currentChangesetId();
            $this->journal->insertChanges($this->buildRows($changesetId, $frame->sequence, $object, $changed, $quality));
        });
    }

    public function recordEvent(ChangeEvent $event, ObjectRef $object, ?int $sequence = null): void
    {
        if (!$this->state->isActive()) {
            return;
        }

        $this->quietly(function () use ($event, $object, $sequence): void {
            $this->journal->insertChanges([[
                'changeset_id' => $this->currentChangesetId(),
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
    public function recordGap(string $reason, int $postId, array $details = []): void
    {
        $this->gaps->object($reason, new ObjectRef('post', $postId), $details);
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

    public function currentChangesetId(): int
    {
        $operation = $this->request->operation();
        $key = $operation === null ? 'edit' : 'operation:' . $operation['id'];
        $id = $this->request->changesetId($key);

        if ($id !== null) {
            return $id;
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

            [$blobId, $restorable, $reason] = $this->storeValue($after);
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
     * @return list<array<string, mixed>>
     */
    private function buildRows(int $changesetId, int $sequence, ObjectRef $object, array $changed, CaptureQuality $quality): array
    {
        $rows = [];

        foreach ($changed as $field => [$before, $after]) {
            [$beforeBlob, $beforeOk, $beforeReason] = $this->storeValue($before);
            [$afterBlob, $afterOk, $afterReason] = $this->storeValue($after);
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
                'adapter_key' => PostFieldsAdapter::KEY,
                'adapter_version' => PostFieldsAdapter::VERSION,
                'field_key' => $field,
                'before_blob_id' => $beforeBlob,
                'after_blob_id' => $afterBlob,
                'before_hash' => $before->hash(),
                'after_hash' => $after->hash(),
                'quality' => $quality->value,
                'restorable' => $restorable,
                'reason_code' => $restorable ? null : $reason,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: int|null, 1: bool, 2: string|null} blob id, stored, reason
     */
    private function storeValue(FieldValue $value): array
    {
        try {
            return [$this->blobs->put($value)['id'], true, null];
        } catch (InvalidPayload $e) {
            return [null, false, $e->reasonCode === 'payload_too_large' ? 'payload_not_stored' : $e->reasonCode];
        }
    }

    /**
     * Journal errors must never break the post save and must not print database
     * errors on screen: they are converted into a diagnostic event and a gap.
     */
    private function quietly(callable $fn): void
    {
        $suppress = $this->db->suppress_errors(true);

        try {
            $fn();
        } finally {
            $this->db->suppress_errors($suppress);
        }
    }
}
