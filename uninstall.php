<?php
/**
 * Uninstall: the history is deleted ONLY when the administrator explicitly allowed
 * it in Settings → Removal. Deactivation and deleting plugin files keep the data.
 *
 * @package SelectiveUndo
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

$selective_undo_uninstall_site = static function (): void {
    global $wpdb;

    $settings = get_option('selective_undo_settings', []);

    foreach (['sundo_hourly_maintenance', 'sundo_process_queue', 'sundo_run_job'] as $hook) {
        wp_unschedule_hook($hook);
    }

    if (!is_array($settings) || empty($settings['delete_data_on_uninstall'])) {
        return;
    }

    $tables = SelectiveUndo\Infrastructure\Database\Tables::fromWpdb($wpdb);
    (new SelectiveUndo\Infrastructure\Database\SchemaManager($wpdb, $tables))->drop();
    (new SelectiveUndo\Infrastructure\WordPress\CapabilityManager())->uninstall();

    foreach ([
        'selective_undo_settings',
        'selective_undo_instance_id',
        'selective_undo_capture_state',
        'selective_undo_storage_stats',
        'selective_undo_last_queue_run',
        'selective_undo_last_maintenance_run',
    ] as $option) {
        delete_option($option);
    }

    delete_transient('selective_undo_db_info');
    delete_transient('selective_undo_restore_ready');
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $selective_undo_site_id) {
        switch_to_blog((int) $selective_undo_site_id);
        $selective_undo_uninstall_site();
        restore_current_blog();
    }
} else {
    $selective_undo_uninstall_site();
}
