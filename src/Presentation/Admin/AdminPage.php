<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Admin;

use SelectiveUndo\Infrastructure\WordPress\Capabilities;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;

/**
 * Admin menu and screens. The React bundle is loaded ONLY on the plugin's own
 * screens; other admin pages get nothing but a row action link.
 */
final class AdminPage
{
    public const SLUG = 'selective-undo';
    public const SLUG_RESTORES = 'selective-undo-restores';
    public const SLUG_SETTINGS = 'selective-undo-settings';
    private const HANDLE = 'selective-undo-admin';

    /** @var array<string, 'history'|'restores'|'settings'> hook suffix => page */
    private array $hooks = [];

    public function __construct(private readonly TrackingPolicy $policy)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
        add_filter('page_row_actions', [$this, 'rowActions'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(SELECTIVE_UNDO_FILE), [$this, 'pluginLinks']);
    }

    public function menu(): void
    {
        $hook = add_menu_page(
            __('Selective Undo', 'selective-undo'),
            __('Selective Undo', 'selective-undo'),
            Capabilities::VIEW_HISTORY,
            self::SLUG,
            [$this, 'render'],
            'dashicons-backup',
            76
        );
        $this->hooks[(string) $hook] = 'history';

        $pages = [
            [self::SLUG, __('History', 'selective-undo'), Capabilities::VIEW_HISTORY, 'history'],
            [self::SLUG_RESTORES, __('Restores', 'selective-undo'), Capabilities::VIEW_HISTORY, 'restores'],
            [self::SLUG_SETTINGS, __('Settings', 'selective-undo'), Capabilities::MANAGE_SETTINGS, 'settings'],
        ];

        foreach ($pages as [$slug, $title, $cap, $page]) {
            $sub = add_submenu_page(self::SLUG, $title . ' ‹ ' . __('Selective Undo', 'selective-undo'), $title, $cap, $slug, [$this, 'render']);

            if ($sub !== false) {
                $this->hooks[(string) $sub] = $page;
            }
        }
    }

    public function render(): void
    {
        echo '<div class="wrap su-wrap">';
        echo '<div id="selective-undo-root"></div>';
        echo '<noscript><p>' . esc_html__('Selective Undo needs JavaScript to show the history.', 'selective-undo') . '</p></noscript>';
        echo '</div>';
    }

    public function enqueue(string $hookSuffix): void
    {
        if (!isset($this->hooks[$hookSuffix])) {
            return;
        }

        $assetFile = SELECTIVE_UNDO_DIR . '/build/index.asset.php';

        if (!is_file($assetFile)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__('Selective Undo: the admin interface is not built. Run "npm install && npm run build".', 'selective-undo') . '</p></div>';
            });

            return;
        }

        /** @var array{dependencies: list<string>, version: string} $asset */
        $asset = require $assetFile;
        $url = plugins_url('build/', SELECTIVE_UNDO_FILE);

        wp_enqueue_script(self::HANDLE, $url . 'index.js', $asset['dependencies'], $asset['version'], true);
        wp_enqueue_style(self::HANDLE, $url . 'index.css', ['wp-components'], $asset['version']);
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        wp_set_script_translations(self::HANDLE, 'selective-undo', SELECTIVE_UNDO_DIR . '/languages');

        // Configuration only: no secrets. The REST nonce is set up by wp-api-fetch itself.
        $config = [
            'version' => SELECTIVE_UNDO_VERSION,
            'adminUrl' => admin_url(),
            'pages' => ['history' => self::SLUG, 'restores' => self::SLUG_RESTORES, 'settings' => self::SLUG_SETTINGS],
            'initialPage' => $this->hooks[$hookSuffix],
            'userId' => get_current_user_id(),
        ];
        wp_add_inline_script(self::HANDLE, 'window.selectiveUndoConfig = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function rowActions(array $actions, \WP_Post $post): array
    {
        if (!$this->policy->isTrackedPostType($post->post_type) || !current_user_can(Capabilities::VIEW_OBJECT, $post->ID)) {
            return $actions;
        }

        $url = add_query_arg(['page' => self::SLUG, 'view' => 'object', 'id' => $post->ID], admin_url('admin.php'));
        $actions['selective_undo'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url($url),
            /* translators: %s: post title. */
            esc_attr(sprintf(__('Change history of “%s”', 'selective-undo'), wp_strip_all_tags(get_the_title($post)))),
            esc_html__('Change history', 'selective-undo')
        );

        return $actions;
    }

    /**
     * @param array<string, string> $links
     *
     * @return array<string, string>
     */
    public function pluginLinks(array $links): array
    {
        if (current_user_can(Capabilities::MANAGE_SETTINGS)) {
            array_unshift($links, sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . self::SLUG_SETTINGS)),
                esc_html__('Settings', 'selective-undo')
            ));
        }

        return $links;
    }
}
