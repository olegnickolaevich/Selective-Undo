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
}
