<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database\Migrations;

use SelectiveUndo\Infrastructure\Database\Migration;
use SelectiveUndo\Infrastructure\Database\SchemaError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Object versions: every change gets a number within its object (object_seq), and
 * the history of an object is ordered by it instead of by row id. Existing rows are
 * numbered in id order. Capture is paused while the schema is outdated, so nothing
 * is recorded during the backfill.
 */
final class Migration005 implements Migration
{
    private const BATCH = 500;

    public function up(\wpdb $db, Tables $t): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$t->objectVersions} (
  object_type  VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  object_id    BIGINT UNSIGNED NOT NULL,
  last_seq     BIGINT NOT NULL,
  PRIMARY KEY (object_type, object_id)
) ENGINE=InnoDB " . $db->get_charset_collate();

        if ($db->query($sql) === false) {
            throw new SchemaError('create_table_failed');
        }

        $exists = $db->get_results($db->prepare("SHOW COLUMNS FROM {$t->changes} LIKE %s", 'object_seq'));

        if (!is_array($exists) || $exists === []) {
            $ok = $db->query("ALTER TABLE {$t->changes}
                ADD COLUMN object_seq BIGINT NOT NULL DEFAULT 0,
                ADD KEY object_field_seq (object_type, object_id, field_key, object_seq),
                ADD KEY object_seq (object_type, object_id, object_seq)");

            if ($ok === false) {
                throw new SchemaError('alter_table_failed');
            }
        }

        $this->backfill($db, $t);
    }

    private function backfill(\wpdb $db, Tables $t): void
    {
        $suppress = $db->suppress_errors(true);
        $windowFunctions = $db->get_var('SELECT ROW_NUMBER() OVER () FROM DUAL') !== null;
        $db->suppress_errors($suppress);

        if ($windowFunctions) {
            $ok = $db->query("UPDATE {$t->changes} c
                JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY object_type, object_id ORDER BY id) AS rn FROM {$t->changes}) x
                  ON x.id = c.id
                SET c.object_seq = x.rn");
        } else {
            $ok = $this->backfillPerObject($db, $t);
        }

        if ($ok === false) {
            throw new SchemaError('backfill_failed');
        }

        $db->query("DELETE FROM {$t->objectVersions}");
        $ok = $db->query("INSERT INTO {$t->objectVersions} (object_type, object_id, last_seq)
            SELECT object_type, object_id, MAX(object_seq) FROM {$t->changes} GROUP BY object_type, object_id");

        if ($ok === false) {
            throw new SchemaError('backfill_failed');
        }
    }

    /**
     * Databases without window functions (MySQL 5.7): one object at a time.
     */
    private function backfillPerObject(\wpdb $db, Tables $t): bool
    {
        [$type, $id] = ['', 0];

        do {
            $objects = (array) $db->get_results($db->prepare(
                "SELECT DISTINCT object_type, object_id FROM {$t->changes}
                 WHERE object_type > %s OR (object_type = %s AND object_id > %d)
                 ORDER BY object_type, object_id LIMIT %d",
                $type,
                $type,
                $id,
                self::BATCH
            ), ARRAY_A);

            foreach ($objects as $object) {
                [$type, $id] = [(string) $object['object_type'], (int) $object['object_id']];
                $db->query('SET @seq := 0');
                $ok = $db->query($db->prepare(
                    "UPDATE {$t->changes} SET object_seq = (@seq := @seq + 1)
                     WHERE object_type = %s AND object_id = %d ORDER BY id",
                    $type,
                    $id
                ));

                if ($ok === false) {
                    return false;
                }
            }
        } while (count($objects) === self::BATCH);

        return true;
    }
}
