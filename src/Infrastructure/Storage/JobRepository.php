<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Application\Restore\ItemOutcome;
use SelectiveUndo\Application\Restore\JobLease;
use SelectiveUndo\Application\Restore\LeaseLost;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Restore\JobCounters;
use SelectiveUndo\Domain\Restore\JobItemStatus;
use SelectiveUndo\Domain\Restore\JobStatus;
use SelectiveUndo\Infrastructure\Database\RestoreConnection;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Restore jobs and their items. State transitions that must be atomic with content
 * writes go through the restore connection; reads use $wpdb.
 */
final class JobRepository
{
    public const LEASE_SECONDS = 60;
    public const MAX_ATTEMPTS = 3;

    private const COUNTER_COLUMNS = [
        'restored' => 'restored_items',
        'already_restored' => 'already_items',
        'conflict' => 'conflict_items',
        'skipped' => 'skipped_items',
        'failed' => 'failed_items',
        'cancelled' => 'cancelled_items',
    ];

    private const ITEM_COLUMNS = 'ji.id, ji.plan_item_id, ji.object_type, ji.object_id, pi.object_subtype, pi.field_key,
        pi.adapter_key, pi.adapter_version, pi.expected_hash, pi.target_hash, pi.target_blob_id, pi.last_change_id';

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly RestoreConnection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t->jobs} WHERE uuid = %s", $uuid), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t->jobs} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdempotency(int $actorUserId, string $keyHash): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->t->jobs} WHERE actor_user_id = %d AND idempotency_key_hash = %s",
            $actorUserId,
            $keyHash
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Takes the lease if the job is not terminal and nobody else holds a live lease.
     */
    public function claim(int $jobId): ?JobLease
    {
        $token = wp_generate_uuid4();
        $affected = $this->connection->write(
            "UPDATE `{$this->t->jobs}`
             SET status = CASE WHEN status = 'queued' THEN 'running' ELSE status END,
                 lease_token = ?, lease_expires_at = UTC_TIMESTAMP() + INTERVAL ? SECOND,
                 started_at = COALESCE(started_at, UTC_TIMESTAMP()), attempt_count = attempt_count + 1,
                 fence_seq = fence_seq + 1, heartbeat_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status IN ('queued', 'running', 'cancel_requested')
               AND (lease_token IS NULL OR lease_expires_at IS NULL OR lease_expires_at < UTC_TIMESTAMP())",
            'sii',
            [$token, self::LEASE_SECONDS, $jobId]
        );

        if ($affected !== 1) {
            return null;
        }

        $row = $this->connection->select(
            "SELECT id, actor_user_id, restore_changeset_id, blog_id FROM `{$this->t->jobs}` WHERE id = ?",
            'i',
            [$jobId]
        )[0];

        return new JobLease($jobId, $token, (int) $row['actor_user_id'], (int) $row['restore_changeset_id'], (int) $row['blog_id']);
    }

    public function release(JobLease $lease): void
    {
        $this->connection->write(
            "UPDATE `{$this->t->jobs}` SET lease_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND lease_token = ?",
            'is',
            [$lease->jobId, $lease->token]
        );
    }

    /**
     * Fencing: fails when the lease was taken over or the job was finished.
     * fence_seq always changes: MySQL reports changed rows, and updating heartbeat_at to
     * the same second would report 0.
     */
    public function fence(RestoreTransaction $tx, JobLease $lease): void
    {
        $affected = $tx->write(
            "UPDATE `{$this->t->jobs}`
             SET fence_seq = fence_seq + 1, heartbeat_at = UTC_TIMESTAMP(),
                 lease_expires_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND lease_token = ? AND status IN ('running', 'cancel_requested')",
            'iis',
            [self::LEASE_SECONDS, $lease->jobId, $lease->token]
        );

        if ($affected !== 1) {
            throw new LeaseLost();
        }
    }

    public function isCancelRequested(int $jobId): bool
    {
        return $this->connection->select(
            "SELECT status FROM `{$this->t->jobs}` WHERE id = ?",
            'i',
            [$jobId]
        )[0]['status'] === JobStatus::CancelRequested->value;
    }

    /**
     * @return array{object: ObjectRef, adapter_key: string}|null
     */
    public function nextPendingObject(int $jobId): ?array
    {
        $rows = $this->connection->select(
            "SELECT ji.object_type, ji.object_id, pi.object_subtype, pi.adapter_key
             FROM `{$this->t->jobItems}` ji JOIN `{$this->t->planItems}` pi ON pi.id = ji.plan_item_id
             WHERE ji.job_id = ? AND ji.status = 'pending'
             ORDER BY ji.id LIMIT 1",
            'i',
            [$jobId]
        );

        if ($rows === []) {
            return null;
        }

        return [
            'object' => new ObjectRef((string) $rows[0]['object_type'], (int) $rows[0]['object_id'], (string) $rows[0]['object_subtype']),
            'adapter_key' => (string) $rows[0]['adapter_key'],
        ];
    }

    /**
     * Non-locking read of pending items of one object (phase 1, outside the transaction).
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function pendingFor(int $jobId, ObjectRef $object): array
    {
        return $this->connection->select(
            'SELECT ' . self::ITEM_COLUMNS . "
             FROM `{$this->t->jobItems}` ji JOIN `{$this->t->planItems}` pi ON pi.id = ji.plan_item_id
             WHERE ji.job_id = ? AND ji.object_type = ? AND ji.object_id = ? AND ji.status = 'pending'
             ORDER BY ji.id",
            'isi',
            [$jobId, $object->type, $object->id]
        );
    }

    /**
     * Locks pending items of one object: a second worker waits and then sees them finished.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function lockPendingFor(RestoreTransaction $tx, int $jobId, ObjectRef $object): array
    {
        return $tx->select(
            'SELECT ' . self::ITEM_COLUMNS . "
             FROM `{$this->t->jobItems}` ji JOIN `{$this->t->planItems}` pi ON pi.id = ji.plan_item_id
             WHERE ji.job_id = ? AND ji.object_type = ? AND ji.object_id = ? AND ji.status = 'pending'
             ORDER BY ji.id
             FOR UPDATE",
            'isi',
            [$jobId, $object->type, $object->id]
        );
    }

    public function finishItem(RestoreTransaction $tx, int $itemId, JobItemStatus $status, ?string $reason, ?int $resultChangeId, ?string $errorCode = null): void
    {
        $tx->write(
            "UPDATE `{$this->t->jobItems}`
             SET status = ?, reason_code = ?, error_code = ?, result_change_id = ?, attempt_count = attempt_count + 1,
                 started_at = COALESCE(started_at, UTC_TIMESTAMP()), finished_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = 'pending'",
            'sssii',
            [$status->value, $reason, $errorCode, $resultChangeId, $itemId]
        );
    }

    /**
     * @param list<ItemOutcome> $outcomes
     */
    public function bumpCounters(RestoreTransaction $tx, int $jobId, array $outcomes): void
    {
        $sets = [];

        foreach ($outcomes as $outcome) {
            $column = self::COUNTER_COLUMNS[$outcome->status->value] ?? null;

            if ($column !== null) {
                $sets[$column] = ($sets[$column] ?? 0) + 1;
            }
        }

        if ($sets === []) {
            return;
        }

        $sql = implode(', ', array_map(static fn (string $c, int $n): string => sprintf('%1$s = %1$s + %2$d', $c, $n), array_keys($sets), $sets));
        $tx->write("UPDATE `{$this->t->jobs}` SET {$sql}, updated_at = UTC_TIMESTAMP() WHERE id = ?", 'i', [$jobId]);
    }

    /**
     * Records a technical failure of one object outside the transaction. After
     * MAX_ATTEMPTS the items are marked failed.
     */
    public function recordFailure(int $jobId, ObjectRef $object, string $errorCode): bool
    {
        $this->connection->write(
            "UPDATE `{$this->t->jobItems}` SET attempt_count = attempt_count + 1, error_code = ?
             WHERE job_id = ? AND object_type = ? AND object_id = ? AND status = 'pending'",
            'sisi',
            [$errorCode, $jobId, $object->type, $object->id]
        );

        $failed = $this->connection->write(
            "UPDATE `{$this->t->jobItems}` SET status = 'failed', finished_at = UTC_TIMESTAMP()
             WHERE job_id = ? AND object_type = ? AND object_id = ? AND status = 'pending' AND attempt_count >= ?",
            'isii',
            [$jobId, $object->type, $object->id, self::MAX_ATTEMPTS]
        );

        if ($failed > 0) {
            $this->connection->write(
                "UPDATE `{$this->t->jobs}` SET failed_items = failed_items + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                'ii',
                [$failed, $jobId]
            );
        }

        return $failed > 0;
    }

    public function cancelPending(int $jobId): int
    {
        $cancelled = $this->connection->write(
            "UPDATE `{$this->t->jobItems}` SET status = 'cancelled', finished_at = UTC_TIMESTAMP()
             WHERE job_id = ? AND status = 'pending'",
            'i',
            [$jobId]
        );

        if ($cancelled > 0) {
            $this->connection->write(
                "UPDATE `{$this->t->jobs}` SET cancelled_items = cancelled_items + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                'ii',
                [$cancelled, $jobId]
            );
        }

        return $cancelled;
    }

    public function counters(int $jobId): JobCounters
    {
        $rows = $this->connection->select(
            "SELECT status, COUNT(*) AS n FROM `{$this->t->jobItems}` WHERE job_id = ? GROUP BY status",
            'i',
            [$jobId]
        );
        $byStatus = [];

        foreach ($rows as $row) {
            $byStatus[(string) $row['status']] = (int) $row['n'];
        }

        return JobCounters::fromStatusCounts($byStatus);
    }

    /**
     * Moves the job to its terminal status. Only the lease holder succeeds, so the
     * "finished" side effects run once.
     */
    public function complete(JobLease $lease, JobStatus $status, JobCounters $c): bool
    {
        return $this->connection->write(
            "UPDATE `{$this->t->jobs}`
             SET status = ?, restored_items = ?, already_items = ?, conflict_items = ?, skipped_items = ?,
                 failed_items = ?, cancelled_items = ?, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(),
                 lease_token = NULL, lease_expires_at = NULL
             WHERE id = ? AND lease_token = ? AND status IN ('running', 'cancel_requested')",
            'siiiiiiis',
            [$status->value, $c->restored, $c->alreadyRestored, $c->conflict, $c->skipped, $c->failed, $c->cancelled, $lease->jobId, $lease->token]
        ) === 1;
    }

    public function requestCancel(int $jobId): bool
    {
        return $this->connection->write(
            "UPDATE `{$this->t->jobs}` SET status = 'cancel_requested', cancel_requested_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status IN ('queued', 'running')",
            'i',
            [$jobId]
        ) === 1;
    }

    public function setPostprocessStatus(int $jobId): void
    {
        $this->db->query($this->db->prepare(
            "UPDATE {$this->t->jobs} j SET postprocess_status = CASE
                WHEN EXISTS (SELECT 1 FROM {$this->t->outbox} o WHERE o.job_id = j.id AND o.status = 'dead') THEN 'failed'
                WHEN EXISTS (SELECT 1 FROM {$this->t->outbox} o WHERE o.job_id = j.id AND o.status = 'pending') THEN 'pending'
                WHEN EXISTS (SELECT 1 FROM {$this->t->outbox} o WHERE o.job_id = j.id) THEN 'done'
                ELSE 'none' END
             WHERE j.id = %d",
            $jobId
        ));
    }

    /**
     * Jobs that need a worker: queued, or running with an expired lease.
     *
     * @return list<int>
     */
    public function recoverable(int $limit = 10): array
    {
        return array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->t->jobs}
             WHERE status IN ('queued', 'running', 'cancel_requested')
               AND blog_id = %d
               AND (lease_token IS NULL OR lease_expires_at < UTC_TIMESTAMP())
             ORDER BY id LIMIT %d",
            get_current_blog_id(),
            $limit
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $jobId, ?string $status = null, int $afterId = 0, int $limit = 100): array
    {
        return (array) $this->db->get_results($this->db->prepare(
            "SELECT ji.id, ji.plan_item_id, ji.object_type, ji.object_id, ji.status, ji.reason_code, ji.error_code,
                    ji.result_change_id, ji.finished_at, pi.field_key, pi.object_subtype, pi.adapter_key
             FROM {$this->t->jobItems} ji JOIN {$this->t->planItems} pi ON pi.id = ji.plan_item_id
             WHERE ji.job_id = %d AND ji.id > %d" . ($status !== null ? ' AND ji.status = %s' : '') . '
             ORDER BY ji.id LIMIT %d',
            ...array_values(array_filter([$jobId, $afterId, $status, $limit], static fn ($v): bool => $v !== null))
        ), ARRAY_A);
    }
}
