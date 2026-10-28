<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Infrastructure\Database\SqlTime;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;

/**
 * Post-commit actions (cache invalidation, revisions, public hooks). Events are
 * written in the same transaction as the restore and delivered at least once.
 */
final class Outbox
{
    public const MAX_ATTEMPTS = 10;
    private const LOCK_SECONDS = 60;

    /** @var array<string, list<callable(array<string, mixed>): void>> */
    private array $handlers = [];

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly EventLog $log,
    ) {
    }

    /**
     * @param callable(array<string, mixed>): void $handler
     */
    public function on(string $topic, callable $handler): void
    {
        $this->handlers[$topic][] = $handler;
    }

    public function off(string $topic, callable $handler): void
    {
        $this->handlers[$topic] = array_values(array_filter($this->handlers[$topic] ?? [], static fn (callable $h): bool => $h !== $handler));
    }

    public function hasHandlers(string $topic): bool
    {
        return ($this->handlers[$topic] ?? []) !== [];
    }

    /**
     * Queues an event from the capture path: one insert on the WordPress connection.
     *
     * @param array<string, mixed> $payload IDs, codes and field keys only.
     */
    public function enqueueNow(string $topic, ?int $jobId, ?ObjectRef $object, array $payload): string
    {
        $uuid = wp_generate_uuid4();
        $payload['event_id'] = $uuid;
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert($this->t->outbox, [
            'event_uuid' => $uuid,
            'topic' => $topic,
            'job_id' => $jobId,
            'object_type' => $object?->type,
            'object_id' => $object?->id,
            'payload_json' => (string) wp_json_encode($payload),
            'status' => 'pending',
            'available_at' => $now,
            'created_at' => $now,
        ]);

        return $uuid;
    }

    /**
     * @param array<string, scalar|list<scalar>|array<string, list<string>>|null> $payload IDs, codes and field keys only.
     */
    public function enqueue(RestoreTransaction $tx, string $topic, ?int $jobId, ?ObjectRef $object, array $payload): string
    {
        $uuid = wp_generate_uuid4();
        $payload['event_id'] = $uuid;
        $tx->write(
            "INSERT INTO `{$this->t->outbox}` (event_uuid, topic, job_id, object_type, object_id, payload_json, status, available_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', " . SqlTime::utc() . ", " . SqlTime::utc() . ")",
            'ssisis',
            [$uuid, $topic, $jobId, $object?->type, $object?->id, (string) wp_json_encode($payload)]
        );

        return $uuid;
    }

    public function dispatchPendingFor(int $jobId, ObjectRef $object): void
    {
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->t->outbox}
             WHERE job_id = %d AND object_type = %s AND object_id = %d AND status = 'pending' ORDER BY id",
            $jobId,
            $object->type,
            $object->id
        ));

        foreach ((array) $ids as $id) {
            $this->dispatch((int) $id);
        }
    }

    /**
     * Delivers due events (cron). Returns the number of processed events.
     */
    public function dispatchDue(int $limit = 50, float $budgetSeconds = 10.0): int
    {
        $started = microtime(true);
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->t->outbox}
             WHERE status = 'pending' AND available_at <= " . SqlTime::utc() . "
               AND (locked_until IS NULL OR locked_until < " . SqlTime::utc() . ")
             ORDER BY id LIMIT %d",
            $limit
        ));
        $done = 0;

        foreach ((array) $ids as $id) {
            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }

            $this->dispatch((int) $id);
            $done++;
        }

        return $done;
    }

    private function dispatch(int $id): void
    {
        $claimed = $this->db->query($this->db->prepare(
            "UPDATE {$this->t->outbox}
             SET locked_until = " . SqlTime::utc(self::LOCK_SECONDS) . ", attempts = attempts + 1
             WHERE id = %d AND status = 'pending' AND (locked_until IS NULL OR locked_until < " . SqlTime::utc() . ")",
            $id
        ));

        if ($claimed !== 1) {
            return; // another process is delivering it
        }

        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t->outbox} WHERE id = %d", $id), ARRAY_A);

        if (!is_array($row)) {
            return;
        }

        $handlers = $this->handlers[(string) $row['topic']] ?? [];
        $payload = json_decode((string) $row['payload_json'], true);

        try {
            if ($handlers === []) {
                throw new \RuntimeException('no_handler');
            }

            foreach ($handlers as $handler) {
                $handler(is_array($payload) ? $payload : []);
            }
            $this->db->query($this->db->prepare(
                "UPDATE {$this->t->outbox} SET status = 'done', done_at = " . SqlTime::utc() . ", locked_until = NULL, last_error_code = NULL WHERE id = %d",
                $id
            ));
        } catch (\Throwable $e) {
            $attempts = (int) $row['attempts'];
            $dead = $attempts >= self::MAX_ATTEMPTS;
            $delay = min(3600, 30 * (2 ** min(10, $attempts)));
            $this->db->query($this->db->prepare(
                "UPDATE {$this->t->outbox}
                 SET status = %s, locked_until = NULL, available_at = " . SqlTime::utc($delay) . ", last_error_code = %s
                 WHERE id = %d",
                $dead ? 'dead' : 'pending',
                substr((new \ReflectionClass($e))->getShortName(), 0, 64),
                $id
            ));
            $this->log->exception('outbox_delivery_failed', $e, isset($row['object_id']) ? (int) $row['object_id'] : null, isset($row['job_id']) ? (int) $row['job_id'] : null);
        }
    }
}
