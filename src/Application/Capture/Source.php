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
    case Restore = 'restore';
    case Unknown = 'unknown';
}
