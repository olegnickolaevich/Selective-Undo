<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Admin;

use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\WordPress\Capabilities;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;

/**
 * List screens of tracked post types: the "Undo" notice after Quick Edit and bulk
 * edit, and the "Last change" column.
 */
final class ListScreenIntegration
{
    public const HANDLE = 'selective-undo-list';

    public function __construct(
        private readonly TrackingPolicy $policy,
        private readonly JournalReader $reader,
        private readonly AdapterInterface $postFields,
    ) {
    }

    public function register(): void
    {
        add_action('load-edit.php', [$this, 'onLoad']);
    }

    public function onLoad(): void
    {
        $screen = get_current_screen();
        $type = $screen !== null && $screen->post_type !== '' ? $screen->post_type : 'post';

        if (!$this->policy->isTrackedPostType($type) || !current_user_can(Capabilities::VIEW_HISTORY)) {
            return;
        }

        (new LastChangeColumn($type, $this->reader, $this->postFields))->register();

        add_action('admin_enqueue_scripts', static function (): void {
            if (!Assets::enqueueScript(self::HANDLE, 'list', ['jquery'])) {
                return;
            }

            $config = Assets::quickUndoConfig() + [
                // Bulk edit redirects back with ?updated=N: show the notice for it once.
                'afterBulkEdit' => isset($_GET['updated']) && absint($_GET['updated']) > 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether to look up the user's own recent operation.
            ];
            wp_add_inline_script(self::HANDLE, 'window.selectiveUndoList = ' . wp_json_encode($config) . ';', 'before');
        });
    }
}
