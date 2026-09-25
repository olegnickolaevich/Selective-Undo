<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Application\Support\ObjectPresenter;
use SelectiveUndo\Application\Support\Time;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Infrastructure\Storage\JobRepository;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;

/**
 * Read model of restore jobs. The owner sees their jobs; users who manage settings see all.
 */
final class JobView
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly PlanRepository $plans,
        private readonly JournalReader $journal,
        private readonly AdapterRegistry $adapters,
        private readonly ObjectPresenter $objects,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forUser(string $uuid, int $userId): ?array
    {
        $job = $this->jobs->findByUuid($uuid);

        if ($job === null || !$this->canSee($job, $userId)) {
            return null;
        }

        return $this->present($job, $userId);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function canSee(array $job, int $userId): bool
    {
        return (int) $job['blog_id'] === get_current_blog_id()
            && ((int) $job['actor_user_id'] === $userId || user_can($userId, 'sundo_manage_settings'));
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    public function present(array $job, int $userId): array
    {
        $plan = $this->plans->findById((int) $job['plan_id']);
        $changeset = $job['restore_changeset_id'] !== null ? $this->journal->changesetById((int) $job['restore_changeset_id']) : null;
        $total = (int) $job['total_items'];
        $done = (int) $job['restored_items'] + (int) $job['already_items'] + (int) $job['conflict_items']
            + (int) $job['skipped_items'] + (int) $job['failed_items'] + (int) $job['cancelled_items'];

        return [
            'id' => (string) $job['uuid'],
            'plan_id' => $plan !== null ? (string) $plan['uuid'] : null,
            'status' => (string) $job['status'],
            'mode' => (string) $job['mode'],
            'actor' => $this->objects->actor($job['actor_user_id']),
            'counters' => [
                'total' => $total,
                'pending' => max(0, $total - $done),
                'restored' => (int) $job['restored_items'],
                'already_restored' => (int) $job['already_items'],
                'conflict' => (int) $job['conflict_items'],
                'skipped' => (int) $job['skipped_items'],
                'failed' => (int) $job['failed_items'],
                'cancelled' => (int) $job['cancelled_items'],
            ],
            'postprocess_status' => (string) $job['postprocess_status'],
            'queue_health' => $this->queueHealth($job),
            'restore_changeset_id' => $changeset !== null ? (string) $changeset['uuid'] : null,
            'created_at' => Time::iso($job['created_at']),
            'started_at' => Time::iso($job['started_at']),
            'finished_at' => Time::iso($job['finished_at']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(array $job, int $userId, ?string $status, int $afterId, int $limit): array
    {
        $out = [];

        foreach ($this->jobs->items((int) $job['id'], $status, $afterId, $limit) as $row) {
            $adapter = $this->adapters->find((string) $row['adapter_key']);
            $out[] = [
                'id' => (int) $row['id'],
                'object' => $this->objects->describe(new ObjectRef((string) $row['object_type'], (int) $row['object_id'], (string) $row['object_subtype']), $userId),
                'field' => (string) $row['field_key'],
                'field_label' => $adapter !== null ? $adapter->fieldLabel((string) $row['field_key']) : (string) $row['field_key'],
                'status' => (string) $row['status'],
                'reason_code' => $row['reason_code'] === null ? ($row['error_code'] === null ? null : (string) $row['error_code']) : (string) $row['reason_code'],
                'result_change_id' => $row['result_change_id'] === null ? null : (int) $row['result_change_id'],
                'finished_at' => Time::iso($row['finished_at']),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function queueHealth(array $job): string
    {
        if (!in_array($job['status'], ['queued', 'running', 'cancel_requested'], true)) {
            return 'ok';
        }

        $updated = strtotime((string) $job['updated_at'] . ' UTC');

        if ($updated !== false && time() - $updated > 15 * MINUTE_IN_SECONDS) {
            return 'unavailable';
        }

        return $updated !== false && time() - $updated > 2 * MINUTE_IN_SECONDS ? 'delayed' : 'ok';
    }
}
