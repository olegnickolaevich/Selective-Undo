<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\History;

use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * CSV export of history METADATA (no values, no titles).
 */
final class HistoryExport
{
    public const MAX_ROWS = 10000;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
    ) {
    }

    public function csv(): string
    {
        $rows = (array) $this->db->get_results($this->db->prepare(
            "SELECT cs.uuid, cs.created_at, cs.kind, cs.source, cs.actor_user_id, c.id AS change_id, c.event,
                    c.object_type, c.object_subtype, c.object_id, c.field_key, c.restorable, c.reason_code, c.quality
             FROM {$this->t->changesets} cs JOIN {$this->t->changes} c ON c.changeset_id = cs.id
             ORDER BY c.id DESC LIMIT %d",
            self::MAX_ROWS
        ), ARRAY_A);

        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            return '';
        }

        fputcsv($out, ['changeset', 'created_at_utc', 'kind', 'source', 'actor_user_id', 'change_id', 'event',
            'object_type', 'post_type', 'object_id', 'field', 'restorable', 'reason', 'quality'], ',', '"', '\\');

        foreach ($rows as $row) {
            // Neutralise spreadsheet formulas in any cell.
            fputcsv($out, array_map(static fn ($v): string => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : (string) $v, array_values($row)), ',', '"', '\\');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
