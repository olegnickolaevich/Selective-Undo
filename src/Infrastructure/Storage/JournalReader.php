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
    private const CHANGE_COLUMNS = 'c.id, c.changeset_id, c.sequence_no, c.object_seq, c.event, c.object_type, c.object_subtype, c.object_id,
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
     * IDs of all changes of one field within a range of object versions (inclusive).
     *
     * @return list<int>
     */
    public function fieldChangeIdsInSeqRange(ObjectRef $object, string $field, int $fromSeq, int $toSeq): array
    {
        return array_values(array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->t->changes}
             WHERE object_type = %s AND object_id = %d AND field_key = %s AND object_seq BETWEEN %d AND %d",
            $object->type,
            $object->id,
            $field,
            $fromSeq,
            $toSeq
        ))));
    }

    public function hasLaterFieldChanges(ObjectRef $object, string $field, int $afterSeq): bool
    {
        return $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t->changes}
             WHERE object_type = %s AND object_id = %d AND field_key = %s AND object_seq > %d LIMIT 1",
            $object->type,
            $object->id,
            $field,
            $afterSeq
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

    /**
     * The latest recorded change of each post, for list screens: one query for all rows.
     *
     * @param list<int> $postIds
     *
     * @return array<int, array{id: int, field_key: string, event: string, created_at: string, actor: int|null}>
     */
    public function latestChangeOfPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->db->get_results($this->db->prepare(
            "SELECT c.id, c.object_id, c.field_key, c.event, c.created_at, cs.actor_user_id
             FROM {$this->t->changes} c JOIN {$this->t->changesets} cs ON cs.id = c.changeset_id
             WHERE c.object_type = 'post' AND c.object_id IN (" . implode(', ', array_fill(0, count($postIds), '%d')) . ")
               AND c.object_seq = (SELECT MAX(l.object_seq) FROM {$this->t->changes} l
                                   WHERE l.object_type = c.object_type AND l.object_id = c.object_id)",
            ...$postIds
        ), ARRAY_A);
        $out = [];

        foreach ((array) $rows as $row) {
            $out[(int) $row['object_id']] = [
                'id' => (int) $row['id'],
                'field_key' => (string) $row['field_key'],
                'event' => (string) $row['event'],
                'created_at' => (string) $row['created_at'],
                'actor' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            ];
        }

        return $out;
    }

    /**
     * The latest recorded change of a field of an object (not informational events).
     *
     * @return array{id: int, field_key: string, created_at: string, actor: int|null, changeset: string}|null
     */
    public function latestFieldChange(string $objectType, int $objectId): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT c.id, c.field_key, c.created_at, cs.actor_user_id, cs.uuid
             FROM {$this->t->changes} c JOIN {$this->t->changesets} cs ON cs.id = c.changeset_id
             WHERE c.object_type = %s AND c.object_id = %d AND c.field_key <> ''
             ORDER BY c.object_seq DESC LIMIT 1",
            $objectType,
            $objectId
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'field_key' => (string) $row['field_key'],
            'created_at' => (string) $row['created_at'],
            'actor' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            'changeset' => (string) $row['uuid'],
        ];
    }

    /**
     * Field changes (update and restore) of an object after a moment, in history order.
     *
     * @param list<string> $fields Only these fields; empty = all.
     *
     * @return list<array<string, mixed>>
     */
    public function fieldChangesAfter(ObjectRef $object, string $afterUtc, array $fields, int $limit): array
    {
        $sql = 'SELECT ' . self::CHANGE_COLUMNS . " FROM {$this->t->changes} c
             WHERE c.object_type = %s AND c.object_id = %d AND c.event IN ('update', 'restore') AND c.created_at > %s";
        $params = [$object->type, $object->id, $afterUtc];

        if ($fields !== []) {
            $sql .= ' AND c.field_key IN (' . implode(', ', array_fill(0, count($fields), '%s')) . ')';
            array_push($params, ...$fields);
        }

        $params[] = $limit;

        return array_values((array) $this->db->get_results($this->db->prepare($sql . ' ORDER BY c.object_seq LIMIT %d', ...$params), ARRAY_A));
    }

    /**
     * IDs and hashes of the changes of one field of an object, newest first, for
     * walking the history backwards (values are loaded one change at a time).
     *
     * @return list<array{id: int, before_blob_id: int|null, after_blob_id: int|null, before_hash: string|null, after_hash: string|null}>
     */
    public function fieldChangesNewestFirst(ObjectRef $object, string $field, int $limit): array
    {
        $rows = (array) $this->db->get_results($this->db->prepare(
            "SELECT id, before_blob_id, after_blob_id, before_hash, after_hash FROM {$this->t->changes}
             WHERE object_type = %s AND object_id = %d AND field_key = %s AND event IN ('update', 'restore')
             ORDER BY object_seq DESC LIMIT %d",
            $object->type,
            $object->id,
            $field,
            $limit
        ), ARRAY_A);
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'before_blob_id' => $row['before_blob_id'] === null ? null : (int) $row['before_blob_id'],
                'after_blob_id' => $row['after_blob_id'] === null ? null : (int) $row['after_blob_id'],
                'before_hash' => $row['before_hash'] === null ? null : (string) $row['before_hash'],
                'after_hash' => $row['after_hash'] === null ? null : (string) $row['after_hash'],
            ];
        }

        return $out;
    }
}
