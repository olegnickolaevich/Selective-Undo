<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Admin;

use SelectiveUndo\Infrastructure\WordPress\Capabilities;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;

/**
 * "Change history" panel in the block editor sidebar of tracked content.
 */
final class EditorPanel
{
    public const HANDLE = 'selective-undo-editor';

    public function __construct(private readonly TrackingPolicy $policy)
    {
    }

    public function register(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $assetFile = SELECTIVE_UNDO_DIR . '/build/editor.asset.php';

        // Only the post editor (not the site or widget editor) of tracked content.
        if ($screen === null || $screen->base !== 'post' || !$this->policy->isTrackedPostType((string) $screen->post_type)
            || !current_user_can('sundo_view_history') || !is_file($assetFile)) {
            return;
        }

        /** @var array{dependencies: list<non-empty-string>, version: string} $asset */
        $asset = require $assetFile;
        $url = plugins_url('build/', SELECTIVE_UNDO_FILE);

        wp_enqueue_script(self::HANDLE, $url . 'editor.js', $asset['dependencies'], $asset['version'], true);
        wp_enqueue_style(self::HANDLE, $url . 'editor.css', [], $asset['version']);
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        wp_set_script_translations(self::HANDLE, 'selective-undo');
        wp_add_inline_script(
            self::HANDLE,
            'window.selectiveUndoEditor = ' . wp_json_encode([
                'historyUrl' => admin_url('admin.php?page=' . AdminPage::SLUG),
                'canRestore' => current_user_can(Capabilities::RESTORE_CHANGES),
            ]) . ';',
            'before'
        );
    }
}
