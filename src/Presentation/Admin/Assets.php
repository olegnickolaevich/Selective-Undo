<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Admin;

use SelectiveUndo\Infrastructure\WordPress\Capabilities;

/**
 * Enqueues a compiled entry from build/ with its generated dependencies.
 */
final class Assets
{
    /**
     * @param list<non-empty-string> $extraDependencies Scripts the bundle uses as globals (jQuery).
     */
    public static function enqueueScript(string $handle, string $entry, array $extraDependencies = []): bool
    {
        $assetFile = SELECTIVE_UNDO_DIR . '/build/' . $entry . '.asset.php';

        if (!is_file($assetFile)) {
            return false;
        }

        /** @var array{dependencies: list<non-empty-string>, version: string} $asset */
        $asset = require $assetFile;
        wp_enqueue_script(
            $handle,
            plugins_url('build/' . $entry . '.js', SELECTIVE_UNDO_FILE),
            array_values(array_unique([...$asset['dependencies'], ...$extraDependencies])),
            $asset['version'],
            true
        );
        wp_set_script_translations($handle, 'selective-undo');

        return true;
    }

    /**
     * What the quick undo notice needs: links and whether the user may restore.
     *
     * @return array{canRestore: bool, historyUrl: string, restoresUrl: string}
     */
    public static function quickUndoConfig(): array
    {
        return [
            'canRestore' => current_user_can(Capabilities::RESTORE_CHANGES),
            'historyUrl' => admin_url('admin.php?page=' . AdminPage::SLUG),
            'restoresUrl' => admin_url('admin.php?page=' . AdminPage::SLUG_RESTORES),
        ];
    }
}
