<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

enum Source: string
{
    /** REST with cookie authentication: the block editor or another logged-in REST client. */
    case BlockEditor = 'block_editor';
    case ClassicEditor = 'classic_editor';
    case QuickEdit = 'quick_edit';
    case BulkEdit = 'bulk_edit';
    case Autosave = 'autosave';
    case AppPassword = 'app_password';
    case Rest = 'rest';
    case XmlRpc = 'xmlrpc';
    case WpCli = 'wp_cli';
    case Cron = 'cron';
    case Ajax = 'ajax';
    /** A form of Settings (General, Reading and others) saved through options.php. */
    case SettingsScreen = 'settings_screen';
    /** Appearance → Menus. */
    case MenusScreen = 'menus_screen';
    case Restore = 'restore';
    /** Changes reconstructed from WordPress revisions saved before recording started. */
    case Revisions = 'revisions';
    /** A save from the block editor after "Undo in the editor". */
    case EditorUndo = 'editor_undo';
    /** A GraphQL request (WPGraphQL). */
    case GraphQl = 'graphql';
    /** Changed outside WordPress (direct SQL, search-replace), found by the reconciliation check. */
    case External = 'external';
    case Unknown = 'unknown';
}
