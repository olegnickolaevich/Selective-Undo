<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Change\ChangesetKind;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Infrastructure\Database\StorageError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Journal writes on the capture path ($wpdb, autocommit).
 */
final class JournalRepository
{
    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
    ) {
    }

    /**
     * @param array{kind: ChangesetKind, source: string, grouping: string, actor: int|null, request_uuid: string,
     *              operation_id?: int|null, restore_job_id?: int|null, label?: string} $data
     *
     * @return array{id: int, uuid: string}
     */
    public function createChangeset(array $data): array
    {
        $uuid = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s');
        $ok = $this->db->insert($this->t->changesets, [
            'uuid' => $uuid,
            'request_uuid' => $data['request_uuid'],
            'operation_id' => $data['operation_id'] ?? null,
            'restore_job_id' => $data['restore_job_id'] ?? null,
            'actor_user_id' => $data['actor'],
            'kind' => $data['kind']->value,
            'source' => $data['source'],
            'grouping_mode' => $data['grouping'],
            'label' => mb_substr($data['label'] ?? '', 0, 255),
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($ok === false) {
            throw new StorageError('changeset_insert_failed');
        }

        return ['id' => (int) $this->db->insert_id, 'uuid' => $uuid];
    }

    /**
     * @param list<array{changeset_id: int, sequence_no: int, event: string, object: ObjectRef, adapter_key: string,
     *                   adapter_version: int, field_key: string, before_blob_id: int|null, after_blob_id: int|null,
     *                   before_hash: string|null, after_hash: string|null, quality: string, restorable: bool,
     *                   reason_code: string|null, reverts_change_id?: int|null}> $rows
     */
    public function insertChanges(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $values = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach ($rows as $r) {
            $values[] = $this->db->prepare(
                '(%d, %d, %s, %s, %s, %d, %s, %d, %s, ' . $this->nullable('%d', $r['before_blob_id']) . ', '
                . $this->nullable('%d', $r['after_blob_id']) . ', ' . $this->nullable('%s', $r['before_hash']) . ', '
                . $this->nullable('%s', $r['after_hash']) . ', %s, %d, ' . $this->nullable('%s', $r['reason_code']) . ', '
                . $this->nullable('%d', $r['reverts_change_id'] ?? null) . ', %s)',
                ...array_values(array_filter([
                    $r['changeset_id'], $r['sequence_no'], $r['event'], $r['object']->type, $r['object']->subtype,
                    $r['object']->id, $r['adapter_key'], $r['adapter_version'], $r['field_key'],
                    $r['before_blob_id'], $r['after_blob_id'], $r['before_hash'], $r['after_hash'],
                    $r['quality'], $r['restorable'] ? 1 : 0, $r['reason_code'], $r['reverts_change_id'] ?? null, $now,
                ], static fn ($v): bool => $v !== null))
            );
        }

        $sql = "INSERT INTO {$this->t->changes}
            (changeset_id, sequence_no, event, object_type, object_subtype, object_id, adapter_key, adapter_version,
             field_key, before_blob_id, after_blob_id, before_hash, after_hash, quality, restorable, reason_code,
             reverts_change_id, created_at)
            VALUES " . implode(', ', $values);

        if ($this->db->query($sql) === false) {
            throw new StorageError('change_insert_failed');
        }
    }

    /**
     * Latest recorded after_hash per field (for chain continuity checks).
     *
     * @param list<string> $fields
     *
     * @return array<string, string|null>
     */
    public function latestAfterHashes(ObjectRef $object, array $fields): array
    {
        $result = array_fill_keys($fields, null);

        if ($fields === []) {
            return $result;
        }

        $parts = [];

        foreach ($fields as $field) {
            $parts[] = $this->db->prepare(
                "(SELECT field_key, after_hash, event FROM {$this->t->changes}
                  WHERE object_type = %s AND object_id = %d AND field_key = %s
                  ORDER BY id DESC LIMIT 1)",
                $object->type,
                $object->id,
                $field
            );
        }

        $rows = $this->db->get_results(implode(' UNION ALL ', $parts), ARRAY_A);

        if (!is_array($rows)) {
            throw new StorageError('journal_read_failed');
        }

        foreach ($rows as $row) {
            $result[(string) $row['field_key']] = $row['after_hash'] === null ? null : (string) $row['after_hash'];
        }

        return $result;
    }

    public function findOpenAutosaveChangeset(int $actor, ObjectRef $object, string $sinceUtc): ?int
    {
        $id = $this->db->get_var($this->db->prepare(
            "SELECT cs.id FROM {$this->t->changesets} cs
             WHERE cs.kind = 'autosave' AND cs.status = 'open' AND cs.actor_user_id = %d AND cs.updated_at >= %s
               AND EXISTS (SELECT 1 FROM {$this->t->changes} c
                           WHERE c.changeset_id = cs.id AND c.object_type = %s AND c.object_id = %d)
             ORDER BY cs.id DESC LIMIT 1",
            $actor,
            $sinceUtc,
            $object->type,
            $object->id
        ));

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array{id: int, before_hash: string|null, after_hash: string|null}|null
     */
    public function findFieldChange(int $changesetId, ObjectRef $object, string $field): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT id, before_hash, after_hash FROM {$this->t->changes}
             WHERE changeset_id = %d AND object_type = %s AND object_id = %d AND field_key = %s
             ORDER BY id DESC LIMIT 1",
            $changesetId,
            $object->type,
            $object->id,
            $field
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'before_hash' => $row['before_hash'] === null ? null : (string) $row['before_hash'],
            'after_hash' => $row['after_hash'] === null ? null : (string) $row['after_hash'],
        ];
    }

    /**
     * Autosave coalescing only: the one allowed mutation of a journal row (invariant I-2).
     */
    public function updateChangeAfter(int $changeId, ?int $afterBlobId, string $afterHash, bool $restorable, ?string $reason): void
    {
        $ok = $this->db->query($this->db->prepare(
            "UPDATE {$this->t->changes}
             SET after_blob_id = " . ($afterBlobId === null ? 'NULL' : '%d') . ", after_hash = %s, restorable = %d, reason_code = "
            . ($reason === null ? 'NULL' : '%s') . ', created_at = %s WHERE id = %d',
            ...array_values(array_filter(
                [$afterBlobId, $afterHash, $restorable ? 1 : 0, $reason, gmdate('Y-m-d H:i:s'), $changeId],
                static fn ($v): bool => $v !== null
            ))
        ));

        if ($ok === false) {
            throw new StorageError('change_update_failed');
        }
    }

    public function deleteChange(int $changeId): void
    {
        if ($this->db->delete($this->t->changes, ['id' => $changeId], ['%d']) === false) {
            throw new StorageError('change_delete_failed');
        }
    }

    public function nextSequence(int $changesetId): int
    {
        return 1 + (int) $this->db->get_var($this->db->prepare(
            "SELECT COALESCE(MAX(sequence_no), 0) FROM {$this->t->changes} WHERE changeset_id = %d",
            $changesetId
        ));
    }

    public function touchChangeset(int $changesetId): void
    {
        $this->db->query($this->db->prepare(
            "UPDATE {$this->t->changesets} SET updated_at = UTC_TIMESTAMP() WHERE id = %d",
            $changesetId
        ));
    }

    /**
     * Seals an open changeset: counters, worst quality and summary.
     * An empty changeset (for example after autosave coalescing) is removed.
     */
    public function seal(int $changesetId): void
    {
        $stats = $this->db->get_row($this->db->prepare(
            "SELECT COUNT(*) AS changes,
                    COUNT(DISTINCT object_type, object_id) AS objects,
                    SUM(quality = 'ambiguous') AS ambiguous,
                    SUM(quality = 'observed') AS observed,
                    SUM(restorable = 0 AND event IN ('update', 'restore')) AS limited
             FROM {$this->t->changes} WHERE changeset_id = %d",
            $changesetId
        ), ARRAY_A);

        $changes = (int) ($stats['changes'] ?? 0);

        if ($changes === 0) {
            $this->db->query($this->db->prepare(
                "DELETE FROM {$this->t->changesets} WHERE id = %d AND status = 'open'",
                $changesetId
            ));

            return;
        }

        $quality = (int) $stats['ambiguous'] > 0 ? 'ambiguous' : ((int) $stats['observed'] > 0 ? 'observed' : 'verified');
        $fields = $this->db->get_col($this->db->prepare(
            "SELECT DISTINCT field_key FROM {$this->t->changes} WHERE changeset_id = %d AND field_key <> '' LIMIT 50",
            $changesetId
        ));
        $events = $this->db->get_col($this->db->prepare(
            "SELECT DISTINCT event FROM {$this->t->changes} WHERE changeset_id = %d",
            $changesetId
        ));

        $this->db->query($this->db->prepare(
            "UPDATE {$this->t->changesets}
             SET status = 'sealed', sealed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(),
                 change_count = %d, object_count = %d, quality = %s, summary_json = %s
             WHERE id = %d AND status = 'open'",
            $changes,
            (int) $stats['objects'],
            $quality,
            (string) wp_json_encode([
                'fields' => array_values(array_map('strval', (array) $fields)),
                'events' => array_values(array_map('strval', (array) $events)),
                'limited' => (int) $stats['limited'],
            ]),
            $changesetId
        ));
    }

    /**
     * Seals autosave sessions of this user for this object (a regular save ends the session).
     */
    public function sealAutosaveSessions(int $actor, ObjectRef $object): void
    {
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT cs.id FROM {$this->t->changesets} cs
             WHERE cs.kind = 'autosave' AND cs.status = 'open' AND cs.actor_user_id = %d
               AND EXISTS (SELECT 1 FROM {$this->t->changes} c
                           WHERE c.changeset_id = cs.id AND c.object_type = %s AND c.object_id = %d)",
            $actor,
            $object->type,
            $object->id
        ));

        foreach ((array) $ids as $id) {
            $this->seal((int) $id);
        }
    }

    private function nullable(string $placeholder, mixed $value): string
    {
        return $value === null ? 'NULL' : $placeholder;
    }
}
