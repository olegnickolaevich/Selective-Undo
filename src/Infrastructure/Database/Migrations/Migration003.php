<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database\Migrations;

use SelectiveUndo\Infrastructure\Database\Migration;
use SelectiveUndo\Infrastructure\Database\SchemaError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Lock rows for databases without named locks (SQLite). Created everywhere so the
 * schema is the same on every database.
 */
final class Migration003 implements Migration
{
    public function up(\wpdb $db, Tables $t): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$t->locks} (
  name        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  owner       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at  DATETIME NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB " . $db->get_charset_collate();

        if ($db->query($sql) === false) {
            throw new SchemaError('create_table_failed');
        }
    }
}
