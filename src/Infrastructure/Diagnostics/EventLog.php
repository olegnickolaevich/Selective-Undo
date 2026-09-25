<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Diagnostics;

use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Technical log without content. Never throws and never records post content,
 * SQL, cookies, headers or keys: context keys are allow-listed and values scalar.
 */
final class EventLog
{
    private const ALLOWED_CONTEXT = [
        'reason', 'field', 'errno', 'count', 'bytes', 'limit', 'status', 'source', 'attempt', 'scope',
        'changesets', 'changes', 'blobs', 'exception', 'hook', 'topic', 'version', 'actor_user_id',
    ];

    private const MAX_PER_REQUEST = 25;

    private int $written = 0;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $tables,
        private readonly string $requestUuid,
    ) {
    }

    /**
     * @param 'debug'|'info'|'warning'|'error' $level
     * @param array<string, mixed>              $context
     */
    public function log(
        string $level,
        string $code,
        array $context = [],
        ?int $jobId = null,
        ?string $objectType = null,
        ?int $objectId = null,
        ?string $adapterKey = null,
        ?int $durationMs = null,
    ): void {
        if ($this->written >= self::MAX_PER_REQUEST) {
            return;
        }

        $this->written++;
        $clean = [];

        foreach ($context as $key => $value) {
            if (in_array($key, self::ALLOWED_CONTEXT, true) && (is_scalar($value) || $value === null)) {
                $clean[$key] = is_string($value) ? substr($value, 0, 190) : $value;
            }
        }

        $suppress = $this->db->suppress_errors(true);

        try {
            $this->db->insert(
                $this->tables->eventLog,
                [
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'level' => $level,
                    'code' => substr(preg_replace('/[^a-z0-9_.:-]/', '', strtolower($code)) ?? 'unknown', 0, 64),
                    'request_uuid' => $this->requestUuid,
                    'job_id' => $jobId,
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                    'adapter_key' => $adapterKey,
                    'duration_ms' => $durationMs,
                    'context_json' => $clean === [] ? null : wp_json_encode($clean),
                ]
            );
        } catch (\Throwable) {
            // Logging must never break the caller.
        } finally {
            $this->db->suppress_errors($suppress);
        }
    }

    public function exception(string $code, \Throwable $e, ?int $objectId = null, ?int $jobId = null): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Developer aid only: exception messages of this plugin never contain content.
            error_log(sprintf('[selective-undo] %s: %s: %s in %s:%d', $code, $e::class, $e->getMessage(), $e->getFile(), $e->getLine())); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        }

        $this->log('error', $code, ['exception' => (new \ReflectionClass($e))->getShortName(), 'reason' => $this->reasonOf($e)], $jobId, $objectId === null ? null : 'post', $objectId);
    }

    private function reasonOf(\Throwable $e): string
    {
        foreach (['reasonCode', 'errorCode'] as $property) {
            if (property_exists($e, $property) && is_string($e->{$property})) {
                return $e->{$property};
            }
        }

        return 'exception';
    }
}
