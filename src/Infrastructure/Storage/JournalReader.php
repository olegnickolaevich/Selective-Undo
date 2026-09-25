<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Read-side queries over the journal.
 */
final class JournalReader
{
    private const CHANGE_COLUMNS = 'c.id, c.changeset_id, c.sequence_no, c.event, c.object_type, c.object_subtype, c.object_id,
        c.adapter_key, c.adapter_version, c.field_key, c.before_blob_id, c.after_blob_id, c.before_hash, c.after_hash,
        c.quality, c.restorable, c.reason_code, c.reverts_change_id, c.created_at';

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array<string, mixed>> id => row
     */
    public function changesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = (array) $this->db->get_results($this->db->prepare(
            'SELECT ' . self::CHANGE_COLUMNS . " FROM {$this->t->changes} c WHERE c.id IN ({$placeholders})",
            ...$ids
        ), ARRAY_A);
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function changesetByUuid(string $uuid): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->t->changesets} WHERE uuid = %s",
            $uuid
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function changesetById(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->t->changesets} WHERE id = %d",
            $id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function changesOfChangeset(int $changesetId, int $limit = 5000): array
    {
        return (array) $this->db->get_results($this->db->prepare(
            'SELECT ' . self::CHANGE_COLUMNS . " FROM {$this->t->changes} c
             WHERE c.changeset_id = %d ORDER BY c.object_type, c.object_id, c.sequence_no, c.id LIMIT %d",
            $changesetId,
            $limit
        ), ARRAY_A);
    }

    /**
     * IDs of all field changes of one field within an ID range (inclusive).
     *
     * @return list<int>
     */
    public function fieldChangeIdsInRange(ObjectRef $object, string $field, int $fromId, int $toId): array
    {
        return array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->t->changes}
             WHERE object_type = %s AND object_id = %d AND field_key = %s AND id BETWEEN %d AND %d",
            $object->type,
            $object->id,
            $field,
            $fromId,
            $toId
        )));
    }

    public function hasLaterFieldChanges(ObjectRef $object, string $field, int $afterId): bool
    {
        return $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t->changes}
             WHERE object_type = %s AND object_id = %d AND field_key = %s AND id > %d LIMIT 1",
            $object->type,
            $object->id,
            $field,
            $afterId
        )) !== null;
    }

    /**
     * Whether history for this object is known to be incomplete since $sinceUtc.
     */
    public function hasGapsSince(ObjectRef $object, string $sinceUtc): bool
    {
        return $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t->gaps}
             WHERE ((scope = 'object' AND object_type = %s AND object_id = %d
                     AND reason IN ('external_write_detected', 'capture_failed', 'payload_not_stored', 'nesting_too_deep'))
                OR (scope = 'global'))
               AND (ended_at IS NULL OR ended_at >= %s)
             LIMIT 1",
            $object->type,
            $object->id,
            $sinceUtc
        )) !== null;
    }
}
