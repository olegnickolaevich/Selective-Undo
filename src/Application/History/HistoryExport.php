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

        $csv = self::line(['changeset', 'created_at_utc', 'kind', 'source', 'actor_user_id', 'change_id', 'event',
            'object_type', 'post_type', 'object_id', 'field', 'restorable', 'reason', 'quality']);

        foreach ($rows as $row) {
            $csv .= self::line(array_values((array) $row));
        }

        return $csv;
    }

    /**
     * One RFC 4180 record. Cells that a spreadsheet would treat as a formula are
     * prefixed with an apostrophe.
     *
     * @param list<mixed> $cells
     */
    private static function line(array $cells): string
    {
        $encoded = array_map(static function (mixed $cell): string {
            $value = (string) $cell;

            if (preg_match('/^[=+\-@]/', $value)) {
                $value = "'" . $value;
            }

            return preg_match('/[",\r\n]/', $value) ? '"' . str_replace('"', '""', $value) . '"' : $value;
        }, $cells);

        return implode(',', $encoded) . "\r\n";
    }
}
