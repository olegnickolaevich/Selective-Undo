<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Retention;

use SelectiveUndo\Application\Capture\CaptureService;
use SelectiveUndo\Application\Capture\CaptureState;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;
use SelectiveUndo\Infrastructure\Settings\Settings;
use SelectiveUndo\Infrastructure\Storage\JournalRepository;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;

/**
 * Hourly maintenance: expiry, sealing, retention by age, quota enforcement and
 * blob garbage collection. Data of active plans and running jobs is never deleted.
 */
final class RetentionService
{
    public const CRON_HOOK = 'sundo_hourly_maintenance';
    public const STATS_OPTION = 'selective_undo_storage_stats';
    /** Rough per-row overhead of journal rows, used for the logical size. */
    private const ROW_OVERHEAD_BYTES = 256;
    private const BATCH = 200;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly Settings $settings,
        private readonly PlanRepository $plans,
        private readonly JournalRepository $journal,
        private readonly CaptureState $state,
        private readonly BlobCollector $blobs,
        private readonly EventLog $log,
    ) {
    }

    /**
     * @return array<string, int> Counters of what was done.
     */
    public function run(float $budgetSeconds = 20.0): array
    {
        $started = microtime(true);
        $done = ['expired_plans' => $this->plans->expireStale()];
        $done['sealed'] = $this->sealStale();
        $done['abandoned_operations'] = (int) $this->db->query(
            "UPDATE {$this->t->operations} SET status = 'abandoned', updated_at = UTC_TIMESTAMP()
             WHERE status = 'active' AND abandon_after < UTC_TIMESTAMP()"
        );

        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->settings->retentionDays() * DAY_IN_SECONDS);
        $done['expired_changesets'] = 0;

        while (microtime(true) - $started < $budgetSeconds) {
            $deleted = $this->deleteChangesets($this->prunableBefore($cutoff, self::BATCH));
            $done['expired_changesets'] += $deleted;

            if ($deleted < self::BATCH) {
                break;
            }
        }

        $done['blobs'] = $this->blobs->collect(max(1.0, $budgetSeconds - (microtime(true) - $started)));
        $done['evicted_changesets'] = $this->enforceQuota(max(1.0, $budgetSeconds - (microtime(true) - $started)));
        $this->pruneAuxiliary($cutoff);
        $this->refreshStats();

        return $done;
    }

    /**
     * @return array{logical_bytes: int, physical_bytes: int, quota_bytes: int, oldest_event_at: string|null, updated_at: int}
     */
    public function stats(): array
    {
        $stats = get_option(self::STATS_OPTION);

        if (!is_array($stats) || !isset($stats['logical_bytes'])) {
            return $this->refreshStats();
        }

        $stats['quota_bytes'] = $this->settings->quotaBytes();

        /** @var array{logical_bytes: int, physical_bytes: int, quota_bytes: int, oldest_event_at: string|null, updated_at: int} */
        return $stats;
    }

    /**
     * @return array{logical_bytes: int, physical_bytes: int, quota_bytes: int, oldest_event_at: string|null, updated_at: int}
     */
    public function refreshStats(): array
    {
        $stats = [
            'logical_bytes' => $this->logicalBytes(),
            'physical_bytes' => (int) $this->db->get_var($this->db->prepare(
                'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
                $this->db->esc_like($this->t->prefix . 'sundo_') . '%'
            )),
            'quota_bytes' => $this->settings->quotaBytes(),
            'oldest_event_at' => $this->db->get_var("SELECT MIN(created_at) FROM {$this->t->changesets}"),
            'updated_at' => time(),
        ];
        update_option(self::STATS_OPTION, $stats, false);

        return $stats;
    }

    public function logicalBytes(): int
    {
        $blobs = (int) $this->db->get_var("SELECT COALESCE(SUM(stored_bytes), 0) FROM {$this->t->blobs}");
        $rows = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t->changes}");

        return $blobs + $rows * self::ROW_OVERHEAD_BYTES;
    }

    /**
     * @param list<int> $ids
     */
    public function deleteChangesets(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $in = implode(',', array_map('intval', $ids));
        $this->db->query("DELETE FROM {$this->t->changes} WHERE changeset_id IN ({$in})");
        $this->db->query("DELETE FROM {$this->t->changesets} WHERE id IN ({$in})");

        return count($ids);
    }

    /**
     * Sealed, unpinned changesets created before $cutoffUtc that no active plan or
     * running job depends on, oldest first.
     *
     * @param list<int|string> $extraParams
     *
     * @return list<int>
     */
    public function prunableBefore(?string $cutoffUtc, int $limit, string $extraWhere = '', array $extraParams = []): array
    {
        [$sql, $params] = $this->prunableSql($cutoffUtc, $extraWhere, $extraParams);

        return array_map('intval', (array) $this->db->get_col($this->db->prepare($sql . ' ORDER BY cs.id LIMIT %d', ...[...$params, $limit])));
    }

    /**
     * @param list<int|string> $extraParams
     */
    public function countPrunable(?string $cutoffUtc, string $extraWhere = '', array $extraParams = []): int
    {
        [$sql, $params] = $this->prunableSql($cutoffUtc, $extraWhere, $extraParams);
        $count = 'SELECT COUNT(*) FROM (' . $sql . ') prunable';

        return (int) $this->db->get_var($params === [] ? $count : $this->db->prepare($count, ...$params));
    }

    /**
     * @param list<int|string> $extraParams
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function prunableSql(?string $cutoffUtc, string $extraWhere, array $extraParams): array
    {
        $sql = "SELECT cs.id FROM {$this->t->changesets} cs
            WHERE cs.status = 'sealed' AND cs.is_pinned = 0"
            . ($cutoffUtc !== null ? ' AND cs.created_at < %s' : '')
            . $extraWhere . "
              AND NOT EXISTS (
                SELECT 1 FROM {$this->t->changes} c
                JOIN {$this->t->planItemSources} s ON s.change_id = c.id
                JOIN {$this->t->planItems} pi ON pi.id = s.plan_item_id
                JOIN {$this->t->plans} p ON p.id = pi.plan_id
                LEFT JOIN {$this->t->jobs} j ON j.plan_id = p.id
                WHERE c.changeset_id = cs.id
                  AND (p.status IN ('preparing', 'ready') OR j.status IN ('queued', 'running', 'cancel_requested')))
              AND NOT EXISTS (
                SELECT 1 FROM {$this->t->jobs} j2
                WHERE j2.restore_changeset_id = cs.id AND j2.status IN ('queued', 'running', 'cancel_requested'))";

        return [$sql, array_values(array_merge($cutoffUtc !== null ? [$cutoffUtc] : [], $extraParams))];
    }

    private function sealStale(): int
    {
        $ids = array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->t->changesets}
             WHERE status = 'open' AND (
                (kind = 'autosave' AND updated_at < %s)
                OR (kind IN ('edit', 'operation') AND updated_at < %s))
             LIMIT 500",
            gmdate('Y-m-d H:i:s', time() - CaptureService::AUTOSAVE_WINDOW_SECONDS),
            gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
        )));

        foreach ($ids as $id) {
            $this->journal->seal($id);
        }

        return count($ids);
    }

    /**
     * Over quota: evict the oldest unpinned history if allowed; if space still cannot
     * be freed, pause capture instead of growing the database without control.
     */
    public function enforceQuota(float $budgetSeconds = 10.0): int
    {
        $started = microtime(true);
        $quota = $this->settings->quotaBytes();
        $evicted = 0;

        if ($this->logicalBytes() > $quota && $this->settings->evictOldest()) {
            // Grow the batch gradually so that no more history than needed is evicted.
            $batch = 1;

            while ($this->logicalBytes() > (int) ($quota * 0.9) && microtime(true) - $started < $budgetSeconds) {
                $deleted = $this->deleteChangesets($this->prunableBefore(null, $batch));
                $evicted += $deleted;
                $this->blobs->collect(5.0, 0);
                $batch = min(50, $batch * 2);

                if ($deleted === 0) {
                    break;
                }
            }

            if ($evicted > 0) {
                $this->log->log('warning', 'quota_eviction', ['changesets' => $evicted]);
            }
        }

        if ($this->logicalBytes() > $quota) {
            $this->state->pause('quota_exceeded');
        } elseif ($this->state->pausedReason() === 'quota_exceeded') {
            $this->state->resume();
        }

        return $evicted;
    }

    private function pruneAuxiliary(string $cutoff): void
    {
        $terminalPlans = array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT p.id FROM {$this->t->plans} p
             WHERE p.created_at < %s AND p.status IN ('expired', 'failed', 'consumed')
               AND NOT EXISTS (SELECT 1 FROM {$this->t->jobs} j WHERE j.plan_id = p.id AND j.status IN ('queued', 'running', 'cancel_requested'))
             LIMIT 500",
            $cutoff
        )));

        if ($terminalPlans !== []) {
            $in = implode(',', $terminalPlans);
            $jobs = implode(',', array_map('intval', (array) $this->db->get_col("SELECT id FROM {$this->t->jobs} WHERE plan_id IN ({$in})"))) ?: '0';
            $this->db->query("DELETE FROM {$this->t->jobItems} WHERE job_id IN ({$jobs})");
            $this->db->query("DELETE FROM {$this->t->outbox} WHERE job_id IN ({$jobs}) AND status <> 'pending'");
            $this->db->query("DELETE FROM {$this->t->jobs} WHERE id IN ({$jobs})");
            $this->db->query("DELETE s FROM {$this->t->planItemSources} s JOIN {$this->t->planItems} i ON i.id = s.plan_item_id WHERE i.plan_id IN ({$in})");
            $this->db->query("DELETE FROM {$this->t->planItems} WHERE plan_id IN ({$in})");
            $this->db->query("DELETE FROM {$this->t->plans} WHERE id IN ({$in})");
        }

        $this->db->query("DELETE FROM {$this->t->outbox} WHERE status = 'done' AND done_at < UTC_TIMESTAMP() - INTERVAL 7 DAY");
        $this->db->query("DELETE FROM {$this->t->eventLog} WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY");
        $this->db->query($this->db->prepare(
            "DELETE FROM {$this->t->gaps} WHERE ended_at IS NOT NULL AND ended_at < %s",
            $cutoff
        ));
        $excess = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t->eventLog}") - 5000;

        if ($excess > 0) {
            $this->db->query($this->db->prepare("DELETE FROM {$this->t->eventLog} ORDER BY id LIMIT %d", $excess));
        }
    }
}
