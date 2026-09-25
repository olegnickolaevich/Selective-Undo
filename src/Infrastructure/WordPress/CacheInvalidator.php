<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

/**
 * Invalidates caches after a restore that bypassed wp_update_post().
 *
 * Full-page caches usually purge on save_post, which a direct SQL restore does not
 * fire, so known cache plugins are purged explicitly (best effort). Other
 * integrations hook into selective_undo/purge_post_cache.
 */
final class CacheInvalidator
{
    public function purgePost(int $postId): void
    {
        clean_post_cache($postId);

        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($postId);
        }

        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($postId);
        }

        if (function_exists('wpsc_delete_post_cache')) {
            wpsc_delete_post_cache($postId);
        }

        if (defined('LSCWP_V')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public purge action of the LiteSpeed Cache plugin.
            do_action('litespeed_purge_post', $postId);
        }

        /**
         * Fires after Selective Undo restored a post so that page caches, CDNs and
         * search indexes can refresh it.
         *
         * @param int $postId Restored post ID.
         */
        do_action('selective_undo/purge_post_cache', $postId);
    }

    /**
     * @return list<string> Detected full-page cache plugins without a built-in integration.
     */
    public function unsupportedPageCaches(): array
    {
        $supported = function_exists('rocket_clean_post') || function_exists('w3tc_flush_post')
            || function_exists('wpsc_delete_post_cache') || defined('LSCWP_V');

        if ($supported || !defined('WP_CACHE') || !WP_CACHE || !file_exists(WP_CONTENT_DIR . '/advanced-cache.php')) {
            return [];
        }

        return has_action('selective_undo/purge_post_cache') ? [] : ['advanced-cache.php'];
    }
}
