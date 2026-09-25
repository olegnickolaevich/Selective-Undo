<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Health;

use SelectiveUndo\Application\Capture\CaptureState;
use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Application\Restore\RestoreReadiness;
use SelectiveUndo\Application\Retention\RetentionService;
use SelectiveUndo\Application\Support\Time;
use SelectiveUndo\Bootstrap\Plugin;
use SelectiveUndo\Infrastructure\Database\DbInfo;
use SelectiveUndo\Infrastructure\Database\SchemaManager;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Settings\Settings;
use SelectiveUndo\Infrastructure\WordPress\CacheInvalidator;

/**
 * Health checks and the overview shown in the header of every screen.
 * Never reports "all protected" while something is degraded.
 */
final class HealthService
{
    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly Settings $settings,
        private readonly SchemaManager $schema,
        private readonly CaptureState $capture,
        private readonly RestoreReadiness $readiness,
        private readonly RetentionService $retention,
        private readonly DbInfo $dbInfo,
        private readonly CacheInvalidator $caches,
        private readonly AdapterRegistry $adapters,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $stats = $this->retention->stats();
        $quota = max(1, $stats['quota_bytes']);
        $usage = $stats['logical_bytes'] / $quota;
        $gaps = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->t->gaps}
             WHERE (ended_at IS NULL OR ended_at > UTC_TIMESTAMP() - INTERVAL 1 DAY)
               AND reason IN ('external_write_detected', 'capture_failed', 'payload_not_stored', 'nesting_too_deep', 'quota_exceeded', 'schema_outdated', 'capture_disabled')"
        );

        return [
            'capture' => $this->capture->describe(),
            'restore_blockers' => $this->readiness->blockers(),
            'storage' => [
                'logical_bytes' => $stats['logical_bytes'],
                'physical_bytes' => $stats['physical_bytes'],
                'quota_bytes' => $stats['quota_bytes'],
                'usage_ratio' => round($usage, 4),
                'level' => $usage >= 0.95 ? 'critical' : ($usage >= 0.8 ? 'warning' : 'ok'),
            ],
            'retention_days' => $this->settings->retentionDays(),
            'retention_max_days' => $this->settings->maxRetentionDays(),
            'oldest_event_at' => Time::iso($stats['oldest_event_at']),
            'gaps_last_24h' => $gaps,
            'tracked_post_types' => $this->settings->trackedPostTypes(),
        ];
    }

    /**
     * @return list<array{id: string, status: string, label: string, message: string, blocks_restore: bool}>
     */
    public function checks(): array
    {
        $checks = [];
        $blockers = $this->readiness->blockers(true);
        $add = static function (string $id, string $status, string $label, string $message, bool $blocks = false) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $status, 'label' => $label, 'message' => $message, 'blocks_restore' => $blocks];
        };

        $add(
            'schema',
            $this->schema->isCurrent() ? 'ok' : 'error',
            __('Database schema', 'selective-undo'),
            $this->schema->isCurrent()
                ? sprintf(/* translators: %d: schema version. */ __('Version %d, all tables present.', 'selective-undo'), SchemaManager::VERSION)
                : __('The schema is outdated. Open any admin page to run the update.', 'selective-undo'),
            !$this->schema->isCurrent()
        );

        $innodb = !in_array('posts_table_not_innodb', $blockers, true) && !in_array('plugin_tables_invalid', $blockers, true);
        $add(
            'transactions',
            $innodb ? 'ok' : 'error',
            __('Transactional tables', 'selective-undo'),
            $innodb
                ? __('Posts and history tables use InnoDB.', 'selective-undo')
                : __('Restoring requires InnoDB tables for posts and history. Recording continues, restoring is blocked.', 'selective-undo'),
            !$innodb
        );

        $connection = array_values(array_filter($blockers, static fn (string $b): bool => str_starts_with($b, 'restore_connection') || str_starts_with($b, 'db_') || $b === 'mysqli_unavailable'));
        $add(
            'restore_connection',
            $connection === [] ? 'ok' : 'error',
            __('Restore connection', 'selective-undo'),
            $connection === []
                ? __('A dedicated transactional connection to the primary database works and reaches the same database.', 'selective-undo')
                : sprintf(/* translators: %s: error code. */ __('The dedicated database connection failed (%s). Restoring is blocked.', 'selective-undo'), implode(', ', $connection)),
            $connection !== []
        );

        $capture = $this->capture->describe();
        $add(
            'capture',
            $capture['state'] === 'active' ? 'ok' : 'warning',
            __('Recording', 'selective-undo'),
            match ($capture['reason']) {
                null => __('Changes of tracked content are being recorded.', 'selective-undo'),
                'capture_disabled' => __('Recording is turned off in the settings.', 'selective-undo'),
                'quota_exceeded' => __('Recording is paused: the history size limit is reached.', 'selective-undo'),
                'schema_outdated' => __('Recording is paused until the database update finishes.', 'selective-undo'),
                default => __('Recording is paused.', 'selective-undo'),
            }
        );

        $overview = $this->overview();
        $level = $overview['storage']['level'];
        $add(
            'storage',
            $level === 'ok' ? 'ok' : ($level === 'warning' ? 'warning' : 'error'),
            __('History size', 'selective-undo'),
            sprintf(
                /* translators: 1: used size, 2: limit. */
                __('%1$s of %2$s used.', 'selective-undo'),
                size_format($overview['storage']['logical_bytes']),
                size_format($overview['storage']['quota_bytes'])
            )
        );

        $packet = $this->dbInfo->maxAllowedPacket();
        $add(
            'max_value',
            'info',
            __('Largest recorded value', 'selective-undo'),
            sprintf(
                /* translators: %s: size. */
                __('Values up to %s are stored. Larger values are listed but cannot be restored.', 'selective-undo'),
                size_format($this->settings->maxValueBytes($packet))
            )
        );

        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $lastRun = (int) get_option('selective_undo_last_queue_run', 0);
        $cronLate = $lastRun > 0 && time() - $lastRun > 2 * HOUR_IN_SECONDS;
        $add(
            'cron',
            $cronLate ? 'warning' : 'ok',
            __('Background tasks', 'selective-undo'),
            $cronLate
                ? __('Background maintenance has not run for over two hours. Rarely visited sites should use a system cron job.', 'selective-undo')
                : ($cronDisabled
                    ? __('WP-Cron is disabled; make sure a system cron job calls wp-cron.php.', 'selective-undo')
                    : __('Maintenance runs through WP-Cron.', 'selective-undo'))
        );

        $pageCaches = $this->caches->unsupportedPageCaches();
        $add(
            'page_cache',
            $pageCaches === [] ? 'ok' : 'warning',
            __('Page cache', 'selective-undo'),
            $pageCaches === []
                ? __('No page cache without an integration was detected.', 'selective-undo')
                : __('A page cache without a known integration is active. After a restore, clear the page cache manually if visitors still see the old version.', 'selective-undo')
        );

        $add(
            'object_cache',
            'info',
            __('Object cache', 'selective-undo'),
            wp_using_ext_object_cache()
                ? __('A persistent object cache is used; restored posts are purged from it.', 'selective-undo')
                : __('No persistent object cache.', 'selective-undo')
        );

        $dropin = file_exists(WP_CONTENT_DIR . '/db.php');
        $add(
            'db_dropin',
            'info',
            __('Database drop-in', 'selective-undo'),
            $dropin
                ? __('A db.php drop-in is installed. Restores use a separate connection to the primary database.', 'selective-undo')
                : __('None.', 'selective-undo')
        );

        return $checks;
    }

    /**
     * Technical diagnostics without content, titles, user names or keys.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        global $wp_version;

        $settings = $this->settings->all();
        $count = fn (string $table): int => (int) $this->db->get_var("SELECT COUNT(*) FROM {$table}");

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'versions' => [
                'plugin' => SELECTIVE_UNDO_VERSION,
                'api' => SELECTIVE_UNDO_API_VERSION,
                'schema' => (int) get_option(SchemaManager::OPTION, 0),
                'wordpress' => $wp_version,
                'php' => PHP_VERSION,
                'database' => $this->dbInfo->serverVersion(),
            ],
            'environment' => [
                'multisite' => is_multisite(),
                'object_cache' => wp_using_ext_object_cache(),
                'db_dropin' => file_exists(WP_CONTENT_DIR . '/db.php'),
                'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'max_allowed_packet' => $this->dbInfo->maxAllowedPacket(),
                'adapters' => array_map(static fn ($a): array => ['key' => $a->key(), 'version' => $a->version()], array_values($this->adapters->all())),
            ],
            'settings' => [
                'capture_enabled' => $settings['capture_enabled'],
                'tracked_post_types' => $settings['tracked_post_types'],
                'retention_days' => $settings['retention_days'],
                'quota_mb' => $settings['quota_mb'],
                'max_value_kb' => $settings['max_value_kb'],
                'evict_oldest_on_quota' => $settings['evict_oldest_on_quota'],
                'create_revision_after_restore' => $settings['create_revision_after_restore'],
            ],
            'checks' => $this->checks(),
            'counts' => [
                'changesets' => $count($this->t->changesets),
                'changes' => $count($this->t->changes),
                'blobs' => $count($this->t->blobs),
                'jobs' => $count($this->t->jobs),
                'outbox_pending' => (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t->outbox} WHERE status = 'pending'"),
                'outbox_dead' => (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t->outbox} WHERE status = 'dead'"),
            ],
            'storage' => $this->retention->stats(),
            'gaps' => (array) $this->db->get_results(
                "SELECT reason, scope, COUNT(*) AS n, SUM(occurrences) AS occurrences, MAX(last_seen_at) AS last_seen_at
                 FROM {$this->t->gaps} GROUP BY reason, scope",
                ARRAY_A
            ),
            'events' => (array) $this->db->get_results(
                "SELECT created_at, level, code, job_id, object_id, adapter_key, duration_ms, context_json
                 FROM {$this->t->eventLog} ORDER BY id DESC LIMIT 200",
                ARRAY_A
            ),
            'instance' => substr(hash('sha256', (string) get_option(Plugin::INSTANCE_OPTION, '')), 0, 12),
        ];
    }
}
