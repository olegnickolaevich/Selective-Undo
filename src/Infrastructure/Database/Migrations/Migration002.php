<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database\Migrations;

use SelectiveUndo\Infrastructure\Database\Migration;
use SelectiveUndo\Infrastructure\Database\SchemaError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * One row per item whose WordPress revisions were imported (or found to have
 * nothing to import). The row is written before any recorded change of the item,
 * which keeps the item's journal ids in chronological order.
 */
final class Migration002 implements Migration
{
    public function up(\wpdb $db, Tables $t): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$t->revisionImports} (
  object_type     VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  object_id       BIGINT UNSIGNED NOT NULL,
  status          VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  change_count    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (object_type, object_id)
) ENGINE=InnoDB " . $db->get_charset_collate();

        if ($db->query($sql) === false) {
            throw new SchemaError('create_table_failed');
        }
    }
}
