<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ChangeEvent;
use SelectiveUndo\Domain\Change\ChangesetKind;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;
use SelectiveUndo\Infrastructure\Settings\Settings;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalRepository;
use SelectiveUndo\Infrastructure\WordPress\PostFieldsAdapter;
use SelectiveUndo\Infrastructure\WordPress\PostRow;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;

/**
 * Turns WordPress revisions saved before recording started into ordinary history,
 * so that changes made shortly before the plugin was installed can be undone too.
 *
 * Consecutive revisions of a post are consecutive saves of its title, content and
 * excerpt; each pair becomes one change set with the revision's author and time.
 * Only revisions inside the retention period are imported.
 *
 * Restore chains rely on an item's journal ids being chronological, so an item is
 * imported before its first recorded change: on the capture path (under a per-item
 * lock) or by the background pass. An item that already has recorded history is
 * never imported.
 */
final class RevisionImporter
{
    public const SINCE_OPTION = 'selective_undo_recording_since';
    public const STATUS_OPTION = 'selective_undo_revision_import';

    /** Fields stored in WordPress revisions. */
    public const FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    private const MAX_REVISIONS_PER_ITEM = 100;
    private const BATCH = 50;
    private const LOCK_WAIT_SECONDS = 10;

    /** @var array<int, true> Items already checked in this request. */
    private array $checked = [];

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly JournalRepository $journal,
        private readonly BlobStore $blobs,
        private readonly Settings $settings,
        private readonly TrackingPolicy $policy,
        private readonly CaptureState $state,
        private readonly EventLog $log,
    ) {
    }

    /**
     * Remembers when recording started. Called on activation and schema updates.
     */
    public function initialize(): void
    {
        if (get_option(self::SINCE_OPTION) !== false) {
            return;
        }

        // Sites that recorded history before this feature existed start at their first record.
        $first = $this->db->get_var("SELECT MIN(created_at) FROM {$this->t->changesets}");
        add_option(self::SINCE_OPTION, is_string($first) && $first !== '' ? $first : gmdate('Y-m-d H:i:s'), '', false);
        update_option(self::STATUS_OPTION, 'pending', false);
    }

    /**
     * Capture path: import the item's revisions before its first recorded change.
     * Never throws; a failure only means that the item has no imported history.
     */
    public function ensureImported(ObjectRef $object): void
    {
        if ($object->type !== 'post' || isset($this->checked[$object->id])) {
            return;
        }

        $this->checked[$object->id] = true;

        try {
            if (!$this->settings->importRevisions() || $this->isSettled($object->id)) {
                return;
            }

            $this->importItem($object->id, self::LOCK_WAIT_SECONDS);
        } catch (\Throwable $e) {
            $this->log->exception('revision_import_failed', $e, $object->id);
        }
    }

    /**
     * Background pass (queue, WP-CLI).
     *
     * @return array{handled: int, items: int, changes: int, done: bool}
     */
    public function run(float $budgetSeconds): array
    {
        $result = ['handled' => 0, 'items' => 0, 'changes' => 0, 'done' => $this->isDone()];

        if ($result['done'] || !$this->settings->importRevisions() || !$this->state->isActive()) {
            return $result;
        }

        $started = microtime(true);

        do {
            $candidates = $this->candidates();

            foreach ($candidates as $postId) {
                $changes = $this->importItem($postId, 0);
                $result['handled']++;

                if ($changes !== null) {
                    $result['items']++;
                    $result['changes'] += $changes;
                }

                if (microtime(true) - $started > $budgetSeconds) {
                    return $result;
                }
            }
        } while (count($candidates) === self::BATCH);

        update_option(self::STATUS_OPTION, 'done', false);
        $result['done'] = true;

        return $result;
    }

    /**
     * Forgets which items were checked; for long-running processes such as tests.
     */
    public function reset(): void
    {
        $this->checked = [];
    }

    public function isDone(): bool
    {
        return get_option(self::STATUS_OPTION, 'done') === 'done';
    }

    /**
     * @return array{enabled: bool, done: bool, items: int, changes: int, since: string|null}
     */
    public function status(): array
    {
        $row = $this->db->get_row(
            "SELECT COUNT(*) AS items, COALESCE(SUM(change_count), 0) AS changes
             FROM {$this->t->revisionImports} WHERE status = 'imported'",
            ARRAY_A
        );
        $since = get_option(self::SINCE_OPTION);

        return [
            'enabled' => $this->settings->importRevisions(),
            'done' => $this->isDone(),
            'items' => (int) ($row['items'] ?? 0),
            'changes' => (int) ($row['changes'] ?? 0),
            'since' => is_string($since) ? $since : null,
        ];
    }

    /**
     * Posts with revisions to import that were not handled yet.
     *
     * @return list<int>
     */
    private function candidates(): array
    {
        $types = $this->settings->trackedPostTypes();

        if ($types === []) {
            return [];
        }

        return array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT DISTINCT r.post_parent FROM {$this->t->posts} r
             JOIN {$this->t->posts} p ON p.ID = r.post_parent
             LEFT JOIN {$this->t->revisionImports} i ON i.object_type = 'post' AND i.object_id = r.post_parent
             WHERE r.post_type = 'revision' AND r.post_name NOT LIKE %s
               AND r.post_date_gmt >= %s AND r.post_date_gmt < %s
               AND p.post_type IN (" . implode(',', array_fill(0, count($types), '%s')) . ')
               AND i.object_id IS NULL
             ORDER BY r.post_parent LIMIT %d',
            '%-autosave-v%',
            $this->windowStart(),
            $this->since(),
            ...[...$types, self::BATCH]
        )));
    }

    /**
     * @return int|null Number of imported changes, or null when nothing was imported.
     */
    private function importItem(int $postId, int $lockWait): ?int
    {
        $lock = 'sundo_import_' . substr(md5($this->db->prefix), 0, 12) . '_' . $postId;

        if ((int) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', $lock, $lockWait)) !== 1) {
            return null;
        }

        try {
            if ($this->isSettled($postId)) {
                return null;
            }

            $post = $this->db->get_row($this->db->prepare(
                "SELECT ID, post_type FROM {$this->t->posts} WHERE ID = %d",
                $postId
            ), ARRAY_A);

            if (!is_array($post) || !$this->policy->shouldCapture($postId, (string) $post['post_type']) || $this->hasHistory($postId)) {
                $this->settle($postId, 'skipped', 0);

                return null;
            }

            $object = new ObjectRef('post', $postId, (string) $post['post_type']);
            $saves = $this->savesFromRevisions($postId);

            if ($saves === []) {
                $this->settle($postId, 'skipped', 0);

                return null;
            }

            return $this->write($object, $saves);
        } finally {
            $this->db->query($this->db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * @param list<array{actor: int, at: string, changes: array<string, array{0: FieldValue, 1: FieldValue}>}> $saves
     */
    private function write(ObjectRef $object, array $saves): ?int
    {
        $suppress = $this->db->suppress_errors(true);
        $this->db->query('START TRANSACTION');
        $changesetIds = [];
        $count = 0;

        try {
            foreach ($saves as $save) {
                $changeset = $this->journal->createChangeset([
                    'kind' => ChangesetKind::Edit,
                    'source' => Source::Revisions->value,
                    'grouping' => 'request',
                    'actor' => $save['actor'] > 0 ? $save['actor'] : null,
                    'request_uuid' => wp_generate_uuid4(),
                    'created_at' => $save['at'],
                ]);
                $changesetIds[] = $changeset['id'];
                $rows = $this->rows($changeset['id'], $object, $save['changes'], $save['at']);
                $this->journal->insertChanges($rows);
                $this->journal->seal($changeset['id']);
                $count += count($rows);
            }

            // A recorded change of this item that slipped in while the lock was not
            // held (capture waits at most LOCK_WAIT_SECONDS) would break the order.
            $foreign = (int) $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->t->changes}
                 WHERE object_type = 'post' AND object_id = %d
                   AND changeset_id NOT IN (" . implode(',', array_fill(0, count($changesetIds), '%d')) . ')
                 LOCK IN SHARE MODE',
                $object->id,
                ...$changesetIds
            ));

            if ($foreign > 0) {
                $this->db->query('ROLLBACK');
                $this->settle($object->id, 'skipped', 0);

                return null;
            }

            $this->settle($object->id, 'imported', $count);
            $this->db->query('COMMIT');

            return $count;
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');

            throw $e;
        } finally {
            $this->db->suppress_errors($suppress);
        }
    }

    /**
     * Consecutive revisions before recording started, newest last. The oldest one
     * fetched is only the starting point of the first imported save.
     *
     * @return list<array{actor: int, at: string, changes: array<string, array{0: FieldValue, 1: FieldValue}>}>
     */
    private function savesFromRevisions(int $postId): array
    {
        $rows = array_reverse((array) $this->db->get_results($this->db->prepare(
            "SELECT ID, post_author, post_date_gmt, post_type, post_status, post_title, post_content, post_excerpt, menu_order
             FROM {$this->t->posts}
             WHERE post_parent = %d AND post_type = 'revision' AND post_name NOT LIKE %s AND post_date_gmt < %s
             ORDER BY post_date_gmt DESC, ID DESC LIMIT %d",
            $postId,
            '%-autosave-v%',
            $this->since(),
            self::MAX_REVISIONS_PER_ITEM + 1
        ), ARRAY_A));
        $windowStart = $this->windowStart();
        $saves = [];

        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            if ((string) $rows[$i]['post_date_gmt'] < $windowStart) {
                continue;
            }

            $before = PostRow::fromDb($rows[$i - 1]);
            $after = PostRow::fromDb($rows[$i]);
            $changes = [];

            foreach (self::FIELDS as $field) {
                if (!$before->field($field)->equals($after->field($field))) {
                    $changes[$field] = [$before->field($field), $after->field($field)];
                }
            }

            if ($changes !== []) {
                $saves[] = [
                    'actor' => (int) $rows[$i]['post_author'],
                    'at' => (string) $rows[$i]['post_date_gmt'],
                    'changes' => $changes,
                ];
            }
        }

        return $saves;
    }

    /**
     * @param array<string, array{0: FieldValue, 1: FieldValue}> $changes
     *
     * @return list<array<string, mixed>>
     */
    private function rows(int $changesetId, ObjectRef $object, array $changes, string $at): array
    {
        $rows = [];

        foreach ($changes as $field => [$before, $after]) {
            [$beforeBlob, $beforeReason] = $this->store($before);
            [$afterBlob, $afterReason] = $this->store($after);
            $reason = $beforeReason ?? $afterReason;

            $rows[] = [
                'changeset_id' => $changesetId,
                'sequence_no' => 1,
                'event' => ChangeEvent::Update->value,
                'object' => $object,
                'adapter_key' => PostFieldsAdapter::KEY,
                'adapter_version' => PostFieldsAdapter::VERSION,
                'field_key' => $field,
                'before_blob_id' => $beforeBlob,
                'after_blob_id' => $afterBlob,
                'before_hash' => $before->hash(),
                'after_hash' => $after->hash(),
                'quality' => CaptureQuality::Verified->value,
                'restorable' => $reason === null,
                'reason_code' => $reason,
                'created_at' => $at,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: int|null, 1: string|null} blob id and the reason it was not stored
     */
    private function store(FieldValue $value): array
    {
        try {
            return [$this->blobs->put($value)['id'], null];
        } catch (InvalidPayload $e) {
            return [null, $e->reasonCode === 'payload_too_large' ? 'payload_not_stored' : $e->reasonCode];
        }
    }

    private function isSettled(int $postId): bool
    {
        return $this->db->get_var($this->db->prepare(
            "SELECT object_id FROM {$this->t->revisionImports} WHERE object_type = 'post' AND object_id = %d",
            $postId
        )) !== null;
    }

    private function hasHistory(int $postId): bool
    {
        return $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t->changes} WHERE object_type = 'post' AND object_id = %d LIMIT 1",
            $postId
        )) !== null;
    }

    private function settle(int $postId, string $status, int $changes): void
    {
        $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$this->t->revisionImports} (object_type, object_id, status, change_count, created_at)
             VALUES ('post', %d, %s, %d, UTC_TIMESTAMP())",
            $postId,
            $status,
            $changes
        ));
    }

    private function since(): string
    {
        $since = get_option(self::SINCE_OPTION);

        return is_string($since) && $since !== '' ? $since : gmdate('Y-m-d H:i:s');
    }

    private function windowStart(): string
    {
        return gmdate('Y-m-d H:i:s', time() - $this->settings->retentionDays() * DAY_IN_SECONDS);
    }
}
