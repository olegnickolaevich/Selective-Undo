<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

/**
 * Current UTC time as a quoted SQL DATETIME literal, computed in PHP.
 *
 * MySQL time functions (UTC_TIMESTAMP, INTERVAL) do not exist on SQLite, so the
 * plugin never uses them. The literal contains only digits, dashes, colons and a
 * space, so it is safe inside SQL and inside $wpdb->prepare() templates.
 */
final class SqlTime
{
    public static function utc(int $offsetSeconds = 0): string
    {
        return "'" . gmdate('Y-m-d H:i:s', time() + $offsetSeconds) . "'";
    }
}
