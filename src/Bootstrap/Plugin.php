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
    }

    public static function onInit(): void
    {
        load_plugin_textdomain('selective-undo', false, dirname(plugin_basename(SELECTIVE_UNDO_FILE)) . '/languages');
    }

    public static function maybeMigrate(): void
    {
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
    }

    public static function deactivate(): void
    {
        foreach (['sundo_hourly_maintenance', 'sundo_process_queue', 'sundo_run_job'] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
