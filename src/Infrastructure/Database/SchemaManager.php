<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

use SelectiveUndo\Infrastructure\Database\Migrations\Migration001;

/**
 * The schema version is stored separately from the plugin version. Migrations run
 * sequentially under a MySQL named lock, so two parallel requests after a plugin
 * update never run them twice.
 */
final class SchemaManager
{
    public const VERSION = 1;
    public const OPTION = 'selective_undo_schema_version';

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $tables,
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

        $lock = 'sundo_migrate_' . substr(md5($this->db->prefix), 0, 16);

        if ((int) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
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
            $this->db->query($this->db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * @return list<string> Problems: missing tables or non-InnoDB engines.
     */
    public function verify(): array
    {
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
    public function drop(): void
    {
        foreach ($this->tables->own() as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
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
        ];
    }
}
