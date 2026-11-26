<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Health;

use SelectiveUndo\Application\Capture\CaptureState;
use SelectiveUndo\Application\Capture\RevisionImporter;
use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Application\Restore\RestoreReadiness;
use SelectiveUndo\Application\Retention\RetentionService;
use SelectiveUndo\Application\Support\SiteFingerprint;
use SelectiveUndo\Application\Support\Time;
use SelectiveUndo\Bootstrap\Plugin;
use SelectiveUndo\Domain\Contracts\TransactionRunner;
use SelectiveUndo\Infrastructure\Database\DbDialect;
use SelectiveUndo\Infrastructure\Database\DbInfo;
use SelectiveUndo\Infrastructure\Database\SchemaManager;
use SelectiveUndo\Infrastructure\Database\SqlTime;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Queue\JobQueue;
use SelectiveUndo\Infrastructure\Settings\Settings;
use SelectiveUndo\Infrastructure\WordPress\CacheInvalidator;

defined('ABSPATH') || exit;

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
        private readonly RevisionImporter $revisions,
        private readonly JobQueue $queue,
        private readonly SiteFingerprint $site,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $stats = $this->retention->stats();
        $quota = max(1, $stats['quota_bytes']);
        $usage = $stats['logical_bytes'] / $quota;
        $gaps = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->t->gaps}
             WHERE (ended_at IS NULL OR ended_at > " . SqlTime::utc(-DAY_IN_SECONDS) . ")
               AND reason IN ('external_write_detected', 'capture_failed', 'payload_not_stored', 'nesting_too_deep', 'quota_exceeded', 'schema_outdated', 'capture_disabled', 'circuit_open', 'site_moved', 'database_restored')"
        );

        return [
            'capture' => $this->capture->describe(),
            'restore_blockers' => $this->readiness->blockers(),
            'restore_mode' => $this->readiness->mode(),
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
            'site_moved' => $this->site->isMoved(),
            'site_moved_reason' => $this->site->reason(),
        ];
    }

    /** @return list<array{id: string, status: string, label: string, message: string, blocks_restore: bool}> */
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
            __('Database schema', 'undoly'),
            $this->schema->isCurrent()
                ? sprintf(/* translators: %d: schema version. */ __('Version %d, all tables present.', 'undoly'), SchemaManager::VERSION)
                : __('The schema is outdated. Open any admin page to run the update.', 'undoly'),
            !$this->schema->isCurrent()
        );

        $compat = $this->readiness->mode() === TransactionRunner::COMPAT;
        $innodb = !in_array('posts_table_not_innodb', $blockers, true) && !in_array('plugin_tables_invalid', $blockers, true);
        $add(
            'transactions',
            $innodb ? 'ok' : 'error',
            __('Transactional tables', 'undoly'),
            match (true) {
                !$innodb => __('Restoring requires InnoDB tables for posts and history. Recording continues, restoring is blocked.', 'undoly'),
                DbDialect::isSqlite() => __('The site uses SQLite. Restores run in transactions of the SQLite database.', 'undoly'),
                default => __('Posts and history tables use InnoDB.', 'undoly'),
            },
            !$innodb
        );

        if ($compat) {
            $add(
                'compat_mode',
                'warning',
                __('Compatibility mode', 'undoly'),
                __('Restores use the WordPress database connection instead of a dedicated one, because the site runs on SQLite or SELECTIVE_UNDO_COMPAT_MODE is set. Every restored value is read back and checked before it is saved, but protection against a dropped connection in the middle of a restore is weaker. This mode is meant for demos and hosts that allow only one database connection.', 'undoly')
            );
        }

        $connection = array_values(array_filter($blockers, static fn (string $b): bool => str_starts_with($b, 'restore_connection') || str_starts_with($b, 'db_') || $b === 'mysqli_unavailable'));
        $add(
            'restore_connection',
            $connection === [] ? 'ok' : 'error',
            __('Restore connection', 'undoly'),
            $connection === []
                ? ($compat
                    ? __('Restores use the WordPress database connection (compatibility mode).', 'undoly')
                    : __('A dedicated transactional connection to the primary database works and reaches the same database.', 'undoly'))
                : sprintf(/* translators: %s: error code. */ __('The dedicated database connection failed (%s). Restoring is blocked.', 'undoly'), implode(', ', $connection)),
            $connection !== []
        );

        $capture = $this->capture->describe();
        $add(
            'capture',
            $capture['state'] === 'active' ? 'ok' : 'warning',
            __('Recording', 'undoly'),
            match ($capture['reason']) {
                null => __('Changes of tracked content are being recorded.', 'undoly'),
                'capture_disabled' => __('Recording is turned off in the settings.', 'undoly'),
                'quota_exceeded' => __('Recording is paused: the history size limit is reached.', 'undoly'),
                'schema_outdated' => __('Recording is paused until the database update finishes.', 'undoly'),
                'circuit_open' => __('Recording is paused for a while: writing the history failed repeatedly or was too slow. It resumes by itself; see the event log for the errors.', 'undoly'),
                default => __('Recording is paused.', 'undoly'),
            }
        );

        if ($this->site->isMoved()) {
            $add(
                'site_moved',
                'warning',
                __('Copied history', 'undoly'),
                $this->site->reason() === SiteFingerprint::RESTORED_REASON
                    ? __('Most items with history changed at once: the database seems to have been restored from a backup. Keep the history to record the differences as changes made outside WordPress, or clear it.', 'undoly')
                    : __('The history was copied from another site (a different address or database). Keep it if this site took over from that one, or clear it.', 'undoly')
            );
        }

        $import = $this->revisions->status();
        $add(
            'revision_import',
            'info',
            __('Earlier changes', 'undoly'),
            match (true) {
                !$import['enabled'] => __('Importing WordPress revisions is turned off in the settings.', 'undoly'),
                !$import['done'] => __('Changes saved in WordPress revisions before recording started are being imported.', 'undoly'),
                default => sprintf(
                    /* translators: 1: number of changes, e.g. "12 changes", 2: number of items, e.g. "3 items". */
                    __('Imported from WordPress revisions: %1$s, %2$s.', 'undoly'),
                    /* translators: %d: number of changes. */
                    sprintf(_n('%d change', '%d changes', $import['changes'], 'undoly'), $import['changes']),
                    /* translators: %d: number of items. */
                    sprintf(_n('%d item', '%d items', $import['items'], 'undoly'), $import['items'])
                ),
            }
        );

        $overview = $this->overview();
        $level = $overview['storage']['level'];
        $add(
            'storage',
            $level === 'ok' ? 'ok' : ($level === 'warning' ? 'warning' : 'error'),
            __('History size', 'undoly'),
            sprintf(
                /* translators: 1: used size, 2: limit. */
                __('%1$s of %2$s used.', 'undoly'),
                size_format($overview['storage']['logical_bytes']),
                size_format($overview['storage']['quota_bytes'])
            )
        );

        $packet = $this->dbInfo->maxAllowedPacket();
        $add(
            'max_value',
            'info',
            __('Largest recorded value', 'undoly'),
            sprintf(
                /* translators: %s: size. */
                __('Values up to %s are stored. Larger values are listed but cannot be restored.', 'undoly'),
                size_format($this->settings->maxValueBytes($packet))
            )
        );

        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $lastRun = (int) get_option('selective_undo_last_queue_run', 0);
        $cronLate = $lastRun > 0 && time() - $lastRun > 2 * HOUR_IN_SECONDS;
        $add(
            'cron',
            $cronLate ? 'warning' : 'ok',
            __('Background tasks', 'undoly'),
            $cronLate
                ? __('Background maintenance has not run for over two hours. Rarely visited sites should use a system cron job.', 'undoly')
                : ($cronDisabled
                    ? __('WP-Cron is disabled; make sure a system cron job calls wp-cron.php.', 'undoly')
                    : ($this->queue->name() === 'action-scheduler'
                        ? __('Maintenance runs through WP-Cron; restores and imports run through Action Scheduler.', 'undoly')
                        : __('Maintenance runs through WP-Cron.', 'undoly')))
        );

        $pageCaches = $this->caches->unsupportedPageCaches();
        $add(
            'page_cache',
            $pageCaches === [] ? 'ok' : 'warning',
            __('Page cache', 'undoly'),
            $pageCaches === []
                ? __('No page cache without an integration was detected.', 'undoly')
                : __('A page cache without a known integration is active. After a restore, clear the page cache manually if visitors still see the old version.', 'undoly')
        );

        $add(
            'object_cache',
            'info',
            __('Object cache', 'undoly'),
            wp_using_ext_object_cache()
                ? __('A persistent object cache is used; restored posts are purged from it.', 'undoly')
                : __('No persistent object cache.', 'undoly')
        );

        $dropin = file_exists(WP_CONTENT_DIR . '/db.php');
        $add(
            'db_dropin',
            'info',
            __('Database drop-in', 'undoly'),
            match (true) {
                !$dropin => __('None.', 'undoly'),
                DbDialect::isSqlite() => __('The SQLite database drop-in is installed.', 'undoly'),
                $compat => __('A db.php drop-in is installed. Restores use the WordPress database connection (compatibility mode).', 'undoly'),
                default => __('A db.php drop-in is installed. Restores use a separate connection to the primary database.', 'undoly'),
            }
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
                'import_revisions' => $settings['import_revisions'],
            ],
            'revision_import' => $this->revisions->status(),
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
