<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Retention;

use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Mark-and-sweep of unreferenced blobs with a grace period instead of reference
 * counters. A capture bumps last_seen_at when it reuses a blob, and the DELETE
 * re-checks last_seen_at atomically, so a concurrent capture can never lose its blob.
 */
final class BlobCollector
{
    public const GRACE_SECONDS = HOUR_IN_SECONDS;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
    ) {
    }

    public function collect(float $budgetSeconds = 10.0, ?int $graceSeconds = null): int
    {
        $grace = $graceSeconds ?? (int) apply_filters('selective_undo/blob_gc_grace_seconds', self::GRACE_SECONDS);
        $threshold = gmdate('Y-m-d H:i:s', time() - max(0, $grace));
        $started = microtime(true);
        $deleted = 0;

        do {
            $ids = array_map('intval', (array) $this->db->get_col($this->db->prepare(
                "SELECT b.id FROM {$this->t->blobs} b
                 WHERE b.last_seen_at <= %s
                   AND NOT EXISTS (SELECT 1 FROM {$this->t->changes} c WHERE c.before_blob_id = b.id)
                   AND NOT EXISTS (SELECT 1 FROM {$this->t->changes} c WHERE c.after_blob_id = b.id)
                   AND NOT EXISTS (SELECT 1 FROM {$this->t->planItems} p WHERE p.target_blob_id = b.id)
                 ORDER BY b.id LIMIT 500",
                $threshold
            )));

            if ($ids === []) {
                break;
            }

            $deleted += (int) $this->db->query($this->db->prepare(
                "DELETE FROM {$this->t->blobs} WHERE id IN (" . implode(',', $ids) . ') AND last_seen_at <= %s',
                $threshold
            ));
        } while (count($ids) === 500 && microtime(true) - $started < $budgetSeconds);

        return $deleted;
    }
}
