<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

final class Capabilities
{
    public const VIEW_HISTORY = 'sundo_view_history';
    public const RESTORE_CHANGES = 'sundo_restore_changes';
    public const MANAGE_SETTINGS = 'sundo_manage_settings';
    public const MANAGE_RETENTION = 'sundo_manage_retention';
    public const EXPORT_HISTORY = 'sundo_export_history';

    /** Meta capabilities: user_can($id, VIEW_OBJECT, $postId, $recordedPostType). */
    public const VIEW_OBJECT = 'sundo_view_object_history';
    public const RESTORE_OBJECT = 'sundo_restore_object';

    public const PRIMITIVE = [
        self::VIEW_HISTORY,
        self::RESTORE_CHANGES,
        self::MANAGE_SETTINGS,
        self::MANAGE_RETENTION,
        self::EXPORT_HISTORY,
    ];
}
