<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

/**
 * Which SQL engine WordPress talks to. SQLite means WordPress Playground or the
 * SQLite Database Integration plugin: restore runs in compatibility mode.
 */
final class DbDialect
{
    public const MYSQL = 'mysql';
    public const SQLITE = 'sqlite';

    private static ?string $detected = null;

    public static function current(): string
    {
        if (self::$detected !== null) {
            return self::$detected;
        }

        global $wpdb;
        $sqlite = (defined('DB_ENGINE') && constant('DB_ENGINE') === 'sqlite')
            || (class_exists('WP_SQLite_DB') && $wpdb instanceof \WP_SQLite_DB);

        if (!isset($wpdb)) {
            return $sqlite ? self::SQLITE : self::MYSQL; // before WordPress connects: do not cache
        }

        return self::$detected = $sqlite ? self::SQLITE : self::MYSQL;
    }

    public static function isSqlite(): bool
    {
        return self::current() === self::SQLITE;
    }

    /**
     * Equality on a BINARY column. SQLite stores binary values as BLOBs, which never
     * equal a text literal, so there both sides are compared as hex.
     *
     * @param string $placeholder '%s' for $wpdb->prepare(), '?' for restore transactions
     */
    public static function binaryEquals(string $column, string $placeholder): string
    {
        return self::isSqlite() ? "HEX({$column}) = {$placeholder}" : "{$column} = {$placeholder}";
    }

    /** The parameter for binaryEquals(). */
    public static function binaryParam(string $bytes): string
    {
        return self::isSqlite() ? strtoupper(bin2hex($bytes)) : $bytes;
    }

    /** Compatibility mode: restore through $wpdb instead of a dedicated connection. */
    public static function compatMode(): bool
    {
        return self::isSqlite() || (defined('SELECTIVE_UNDO_COMPAT_MODE') && constant('SELECTIVE_UNDO_COMPAT_MODE'));
    }
}
