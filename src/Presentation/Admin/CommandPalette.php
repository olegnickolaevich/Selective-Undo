<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Admin;

use SelectiveUndo\Infrastructure\WordPress\Capabilities;

/**
 * Commands in the WordPress command palette (Ctrl+K / ⌘K). Added only where
 * WordPress already loads the palette (every admin screen since 6.9, the editors
 * before that), so older sites do not load it just for these commands.
 */
final class CommandPalette
{
    public const HANDLE = 'selective-undo-commands';

    public function register(): void
    {
        // After core's wp_enqueue_command_palette_assets() (priority 10).
        add_action('admin_enqueue_scripts', [$this, 'enqueue'], 20);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue'], 20);
    }

    public function enqueue(): void
    {
        if (wp_script_is(self::HANDLE) || !wp_script_is('wp-commands') || !current_user_can(Capabilities::VIEW_HISTORY)) {
            return;
        }

        if (Assets::enqueueScript(self::HANDLE, 'commands')) {
            wp_add_inline_script(self::HANDLE, 'window.selectiveUndoCommands = ' . wp_json_encode(Assets::quickUndoConfig()) . ';', 'before');
        }
    }
}
