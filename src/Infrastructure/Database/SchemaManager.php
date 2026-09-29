<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

use SelectiveUndo\Infrastructure\Database\Migrations\Migration001;
use SelectiveUndo\Domain\Contracts\LockService;
use SelectiveUndo\Infrastructure\Database\Migrations\Migration002;
use SelectiveUndo\Infrastructure\Database\Migrations\Migration003;

/**
 * The schema version is stored separately from the plugin version. Migrations run
 * sequentially under a lock, so two parallel requests after a plugin
 * update never run them twice.
 */
final class SchemaManager
{
    public const VERSION = 3;
    public const OPTION = 'selective_undo_schema_version';

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $tables,
        private readonly LockService $locks,
    ) {
    }

    public function isCurrent(): bool
    {
        return (int) get_option(self::OPTION, 0) >= self::VERSION;
    }

    /**
     * Called on activation, admin_init, WP-CLI and cron, never on the front end.
     * While the schema is outdated capture is paused (gap schema_outdated).
     *
     * @return 'up_to_date'|'migrated'|'busy'
     */
    public function migrate(): string
    {
        if ($this->isCurrent()) {
            return 'up_to_date';
        }

        if (DbDialect::isSqlite()) {
            // The lock table itself is needed to take the migration lock.
            (new Migration003())->up($this->db, $this->tables);
        }

        if (!$this->locks->acquire('migrate', 0)) {
            return 'busy';
        }

        try {
            // Another process may have finished the migration meanwhile.
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            $current = (int) get_option(self::OPTION, 0);

            foreach ($this->migrations() as $version => $migration) {
                if ($version <= $current) {
                    continue;
                }

                $migration->up($this->db, $this->tables);
                update_option(self::OPTION, $version, true);
            }

            $problems = $this->verify();

            if ($problems !== []) {
                throw new SchemaError(implode(',', $problems));
            }

            return 'migrated';
        } finally {
            $this->locks->release('migrate');
        }
    }

    /**
     * @return list<string> Problems: missing tables or non-InnoDB engines.
     */
    public function verify(): array
    {
        if (DbDialect::isSqlite()) {
            // No information_schema and no engines: only check that the tables exist.
            $names = (array) $this->db->get_col($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($this->tables->prefix . 'sundo_') . '%'));
            $missing = array_diff($this->tables->own(), array_map('strval', $names));

            return array_values(array_map(static fn (string $table): string => 'missing:' . $table, $missing));
        }

        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
                $this->db->esc_like($this->tables->prefix . 'sundo_') . '%'
            ),
            ARRAY_A
        );

        $engines = [];

        foreach ((array) $rows as $row) {
            $engines[(string) $row['name']] = strtolower((string) $row['engine']);
        }

        $problems = [];

        foreach ($this->tables->own() as $table) {
            if (!isset($engines[$table])) {
                $problems[] = 'missing:' . $table;
            } elseif ($engines[$table] !== 'innodb') {
                $problems[] = 'engine:' . $table;
            }
        }

        return $problems;
    }

    /**
     * Drops all plugin tables. Used by uninstall only.
     */
    public static function drop(\wpdb $db, Tables $tables): void
    {
        foreach ($tables->own() as $table) {
            $db->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
        }

        delete_option(self::OPTION);
    }

    /**
     * @return array<int, Migration>
     */
    private function migrations(): array
    {
        return [
            1 => new Migration001(),
            2 => new Migration002(),
            3 => new Migration003(),
        ];
    }
}
