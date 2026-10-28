<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database\Migrations;

use SelectiveUndo\Infrastructure\Database\Migration;
use SelectiveUndo\Infrastructure\Database\SchemaError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Index for the content type filter of the history list: without it, MySQL reads
 * every change to find the change sets of one type (1.4 s on a million changes;
 * 0.1 s for a common type and a few ms for a rare one with it).
 */
final class Migration006 implements Migration
{
    public function up(\wpdb $db, Tables $t): void
    {
        $indexes = array_map(
            static fn (array $row): string => (string) ($row['Key_name'] ?? ''),
            (array) $db->get_results("SHOW INDEX FROM {$t->changes}", ARRAY_A)
        );

        if (in_array('subtype_changeset', $indexes, true)) {
            return;
        }

        if ($db->query("ALTER TABLE {$t->changes} ADD KEY subtype_changeset (object_subtype, changeset_id)") === false) {
            throw new SchemaError('alter_table_failed');
        }
    }
}
