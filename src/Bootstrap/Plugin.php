<?php

declare(strict_types=1);

namespace SelectiveUndo\Bootstrap;

use SelectiveUndo\Api;

/**
 * Plugin entry point: wires services to WordPress hooks.
 */
final class Plugin
{
    public const INSTANCE_OPTION = 'selective_undo_instance_id';

    private static ?Services $services = null;
    private static ?Api $api = null;

    public static function services(): Services
    {
        global $wpdb;

        return self::$services ??= new Services($wpdb);
    }

    public static function api(): Api
    {
        return self::$api ??= new Api(self::services());
    }

    public static function boot(): void
    {
        $s = self::services();

        $s->capabilities()->register();
        $s->observer()->register();

        add_action('init', [self::class, 'onInit']);
        add_action('admin_init', [self::class, 'maybeMigrate']);

        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action(\SelectiveUndo\Application\Restore\ExecuteRestoreJob::CRON_HOOK, [self::class, 'runJob']);
        add_action(self::QUEUE_HOOK, [self::class, 'processQueue']);
        add_action(\SelectiveUndo\Application\Retention\RetentionService::CRON_HOOK, [self::class, 'runMaintenance']);
        $s->privacy()->register();
        add_action('rest_api_init', static fn () => (new \SelectiveUndo\Presentation\Rest\RestApi(self::services()))->register());

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('selective-undo', \SelectiveUndo\Presentation\Cli\CliCommand::class);
        }
    }

    public static function runMaintenance(): void
    {
        update_option('selective_undo_last_maintenance_run', time(), false);

        try {
            self::services()->retention()->run();
        } catch (\Throwable $e) {
            self::services()->eventLog()->exception('maintenance_failed', $e);
        }
    }

    public const QUEUE_HOOK = 'sundo_process_queue';

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public static function cronSchedules(array $schedules): array
    {
        $schedules['sundo_five_minutes'] ??= [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every five minutes (Selective Undo)', 'selective-undo'),
        ];

        return $schedules;
    }

    public static function runJob(int|string $jobId): void
    {
        self::services()->executeJob()->run((int) $jobId);
    }

    /**
     * Recovers stuck jobs and delivers due outbox events.
     */
    public static function processQueue(): void
    {
        $s = self::services();
        update_option('selective_undo_last_queue_run', time(), false);

        foreach ($s->jobs()->recoverable() as $jobId) {
            $s->executeJob()->run($jobId, 10.0);
        }

        $s->outbox()->dispatchDue();
    }

    public static function onInit(): void
    {
        load_plugin_textdomain('selective-undo', false, dirname(plugin_basename(SELECTIVE_UNDO_FILE)) . '/languages');
    }

    public static function maybeMigrate(): void
    {
        self::scheduleEvents();
        $schema = self::services()->schema();

        if (!$schema->isCurrent()) {
            try {
                $schema->migrate();
            } catch (\Throwable $e) {
                self::services()->eventLog()->exception('schema_migration_failed', $e);
            }
        }
    }

    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            deactivate_plugins(plugin_basename(SELECTIVE_UNDO_FILE), true, true);
            wp_die(
                esc_html__('Selective Undo cannot be network activated. Activate it on individual sites instead.', 'selective-undo'),
                esc_html__('Plugin activation', 'selective-undo'),
                ['back_link' => true]
            );
        }

        $s = self::services();
        $s->schema()->migrate();
        $s->capabilities()->install();

        if (get_option(self::INSTANCE_OPTION) === false) {
            add_option(self::INSTANCE_OPTION, wp_generate_uuid4(), '', true);
        }

        if (get_option($s->settings()::OPTION) === false) {
            add_option($s->settings()::OPTION, $s->settings()->defaults(), '', true);
        }

        self::scheduleEvents();
    }

    public static function scheduleEvents(): void
    {
        add_filter('cron_schedules', [self::class, 'cronSchedules']);

        if (!wp_next_scheduled(self::QUEUE_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'sundo_five_minutes', self::QUEUE_HOOK);
        }

        if (!wp_next_scheduled(\SelectiveUndo\Application\Retention\RetentionService::CRON_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', \SelectiveUndo\Application\Retention\RetentionService::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        foreach (['sundo_hourly_maintenance', self::QUEUE_HOOK, 'sundo_run_job'] as $hook) {
            wp_unschedule_hook($hook);
        }
    }
}
