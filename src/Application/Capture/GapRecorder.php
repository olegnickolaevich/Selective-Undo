<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Records periods and objects for which history is known to be incomplete.
 * Never throws: it is called from failure paths.
 */
final class GapRecorder
{
    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
    ) {
    }

    public function openGlobal(string $reason): void
    {
        $this->quietly(function () use ($reason): void {
            $open = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->t->gaps} WHERE scope = 'global' AND reason = %s AND ended_at IS NULL LIMIT 1",
                $reason
            ));

            if ($open !== null) {
                return;
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->db->insert($this->t->gaps, [
                'reason' => $reason,
                'scope' => 'global',
                'occurrences' => 1,
                'started_at' => $now,
                'last_seen_at' => $now,
            ]);
        });
    }

    public function closeGlobal(string $reason): void
    {
        $this->quietly(function () use ($reason): void {
            $this->db->query($this->db->prepare(
                "UPDATE {$this->t->gaps} SET ended_at = UTC_TIMESTAMP(), last_seen_at = UTC_TIMESTAMP()
                 WHERE scope = 'global' AND reason = %s AND ended_at IS NULL",
                $reason
            ));
        });
    }

    /**
     * Object-level signal. Repeated signals within a day are aggregated.
     *
     * @param array<string, scalar> $details Codes and numbers only, never content.
     */
    public function object(string $reason, ObjectRef $object, array $details = []): void
    {
        $this->quietly(function () use ($reason, $object, $details): void {
            $id = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->t->gaps}
                 WHERE scope = 'object' AND reason = %s AND object_type = %s AND object_id = %d
                   AND last_seen_at > UTC_TIMESTAMP() - INTERVAL 1 DAY
                 ORDER BY id DESC LIMIT 1",
                $reason,
                $object->type,
                $object->id
            ));

            if ($id !== null) {
                $this->db->query($this->db->prepare(
                    "UPDATE {$this->t->gaps} SET occurrences = occurrences + 1, last_seen_at = UTC_TIMESTAMP(), ended_at = UTC_TIMESTAMP() WHERE id = %d",
                    (int) $id
                ));

                return;
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->db->insert($this->t->gaps, [
                'reason' => $reason,
                'scope' => 'object',
                'object_type' => $object->type,
                'object_id' => $object->id,
                'occurrences' => 1,
                'started_at' => $now,
                'last_seen_at' => $now,
                'ended_at' => $now,
                'details_json' => $details === [] ? null : wp_json_encode($details),
            ]);
        });
    }

    private function quietly(callable $fn): void
    {
        $suppress = $this->db->suppress_errors(true);

        try {
            $fn();
        } catch (\Throwable) {
            // Gap recording is best effort.
        } finally {
            $this->db->suppress_errors($suppress);
        }
    }
}
