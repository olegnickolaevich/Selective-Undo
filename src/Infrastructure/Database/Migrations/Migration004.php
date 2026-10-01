<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database\Migrations;

use SelectiveUndo\Infrastructure\Database\Migration;
use SelectiveUndo\Infrastructure\Database\SchemaError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Merge state of a plan item: the strategy and the conflicts (indexes, labels and
 * hashes of both sides; the texts themselves are recomputed on request).
 */
final class Migration004 implements Migration
{
    public function up(\wpdb $db, Tables $t): void
    {
        $exists = $db->get_results($db->prepare("SHOW COLUMNS FROM {$t->planItems} LIKE %s", 'merge_state'));

        if (is_array($exists) && $exists !== []) {
            return;
        }

        if ($db->query("ALTER TABLE {$t->planItems} ADD COLUMN merge_state TEXT NULL") === false) {
            throw new SchemaError('alter_table_failed');
        }
    }
}
