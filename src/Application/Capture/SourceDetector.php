<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

/**
 * Best-effort classification of where a change came from. Used for labels and
 * filters only, never for security decisions.
 */
final class SourceDetector
{
    public function detect(): Source
    {
        if (defined('WP_CLI') && WP_CLI) {
            return Source::WpCli;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return Source::Autosave;
        }

        if (wp_doing_cron()) {
            return Source::Cron;
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return Source::XmlRpc;
        }

        if ((function_exists('is_graphql_request') && is_graphql_request()) || (defined('GRAPHQL_REQUEST') && GRAPHQL_REQUEST)) {
            if (function_exists('rest_get_authenticated_app_password') && rest_get_authenticated_app_password() !== null) {
                return Source::AppPassword;
            }

            return Source::GraphQl;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            if (function_exists('rest_get_authenticated_app_password') && rest_get_authenticated_app_password() !== null) {
                return Source::AppPassword;
            }

            return is_user_logged_in() ? Source::BlockEditor : Source::Rest;
        }

        // phpcs:disable WordPress.Security.NonceVerification -- classification only.
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';

        if (wp_doing_ajax()) {
            return $action === 'inline-save' ? Source::QuickEdit : Source::Ajax;
        }

        if (is_admin()) {
            global $pagenow;

            if ($pagenow === 'edit.php' && isset($_REQUEST['bulk_edit'])) {
                return Source::BulkEdit;
            }

            if ($pagenow === 'post.php') {
                return isset($_GET['meta-box-loader']) ? Source::BlockEditor : Source::ClassicEditor;
            }
        }
        // phpcs:enable

        return Source::Unknown;
    }

    /**
     * The block editor's second request of a save: the classic meta boxes (ACF and
     * other fields) are posted right after the REST request that saved the post.
     */
    public function isMetaBoxSave(): bool
    {
        global $pagenow;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- classification only.
        return is_admin() && $pagenow === 'post.php' && isset($_GET['meta-box-loader']);
    }

    /**
     * The token the block editor sends with every request of one click on Save:
     * the post itself, fields that plugins save through their own endpoints, meta boxes.
     */
    public function saveToken(): ?string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as a UUID below.
        $raw = isset($_SERVER['HTTP_X_SELECTIVE_UNDO_SAVE']) ? wp_unslash((string) $_SERVER['HTTP_X_SELECTIVE_UNDO_SAVE']) : '';

        return is_string($raw) && wp_is_uuid($raw) ? strtolower($raw) : null;
    }
}
