<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\History;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Application\Support\ObjectPresenter;
use SelectiveUndo\Application\Support\Time;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Storage\JournalReader;

/**
 * History read model with cursor pagination (by id, newest first) and per-object
 * permission filtering. Nothing about objects the viewer cannot see is revealed
 * besides a count.
 */
final class HistoryQuery
{
    public const MAX_LIMIT = 100;
    private const MAX_ROUNDS = 5;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly JournalReader $journal,
        private readonly AdapterRegistry $adapters,
        private readonly ObjectPresenter $objects,
    ) {
    }

    /**
     * @param array{actor?: int, kind?: string, source?: string, object_type?: string, object_id?: int,
     *              subtype?: string, from?: string, to?: string, search?: string} $filters
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(int $userId, array $filters, ?string $cursor, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $beforeId = $cursor !== null ? $this->decodeCursor($cursor) : PHP_INT_MAX;
        [$where, $params] = $this->where($filters);

        if ($where === null) {
            return ['items' => [], 'next_cursor' => null];
        }

        $items = [];
        $lastScanned = null;
        $exhausted = false;

        for ($round = 0; $round < self::MAX_ROUNDS && count($items) < $limit; $round++) {
            $batch = (array) $this->db->get_results($this->db->prepare(
                "SELECT * FROM {$this->t->changesets} cs WHERE cs.id < %d {$where} ORDER BY cs.id DESC LIMIT %d",
                ...[$beforeId, ...$params, $limit * 2]
            ), ARRAY_A);

            if ($batch === []) {
                $exhausted = true;
                break;
            }

            $details = $this->aggregate(array_map(static fn (array $r): int => (int) $r['id'], $batch));

            foreach ($batch as $row) {
                $lastScanned = (int) $row['id'];
                $beforeId = $lastScanned;
                $item = $this->present($row, $details[(int) $row['id']] ?? null, $userId, 3);

                if ($item !== null) {
                    $items[] = $item;

                    if (count($items) >= $limit) {
                        break;
                    }
                }
            }

            $consumedWholeBatch = $lastScanned === (int) $batch[count($batch) - 1]['id'];

            if (count($batch) < $limit * 2 && $consumedWholeBatch) {
                $exhausted = true;
                break;
            }
        }

        return [
            'items' => $items,
            'next_cursor' => !$exhausted && $lastScanned !== null ? $this->encodeCursor($lastScanned) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DomainError
     */
    public function changeset(string $uuid, int $userId): array
    {
        $row = $this->journal->changesetByUuid($uuid);

        if ($row === null) {
            throw new DomainError('su_not_found', 404, __('This operation no longer exists in the history.', 'selective-undo'));
        }

        $details = $this->aggregate([(int) $row['id']]);
        $item = $this->present($row, $details[(int) $row['id']] ?? null, $userId, 500);

        if ($item === null) {
            throw new DomainError('su_not_found', 404, __('This operation no longer exists in the history.', 'selective-undo'));
        }

        $visible = [];

        foreach ($item['objects'] as $object) {
            $visible[$object['type'] . ':' . $object['id']] = $object;
        }

        $objects = [];

        foreach ($this->journal->changesOfChangeset((int) $row['id']) as $change) {
            $key = $change['object_type'] . ':' . $change['object_id'];

            if (!isset($visible[$key])) {
                continue;
            }

            $objects[$key] ??= ['object' => $visible[$key], 'changes' => []];
            $objects[$key]['changes'][] = $this->presentChange($change);
        }

        $item['objects'] = array_values($objects);

        return $item;
    }

    /**
     * @return array{object: array<string, mixed>, items: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws DomainError
     */
    public function objectTimeline(ObjectRef $object, int $userId, ?string $cursor, int $limit): array
    {
        $recorded = $this->db->get_var($this->db->prepare(
            "SELECT object_subtype FROM {$this->t->changes} WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
            $object->type,
            $object->id
        ));
        $ref = new ObjectRef($object->type, $object->id, (string) $recorded);

        if (!user_can($userId, 'sundo_view_object_history', $ref->id, $ref->subtype)) {
            throw new DomainError('su_forbidden', 403, __('You are not allowed to view the history of this item.', 'selective-undo'));
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $beforeId = $cursor !== null ? $this->decodeCursor($cursor) : PHP_INT_MAX;
        $rows = (array) $this->db->get_results($this->db->prepare(
            "SELECT c.*, cs.uuid AS changeset_uuid, cs.kind, cs.source, cs.actor_user_id, cs.operation_id, cs.restore_job_id, cs.label
             FROM {$this->t->changes} c JOIN {$this->t->changesets} cs ON cs.id = c.changeset_id
             WHERE c.object_type = %s AND c.object_id = %d AND c.id < %d
             ORDER BY c.id DESC LIMIT %d",
            $ref->type,
            $ref->id,
            $beforeId,
            $limit + 1
        ), ARRAY_A);
        $next = null;

        if (count($rows) > $limit) {
            array_pop($rows);
            $next = $this->encodeCursor((int) $rows[count($rows) - 1]['id']);
        }

        $items = [];

        foreach ($rows as $row) {
            $item = $this->presentChange($row);
            $item['changeset'] = [
                'id' => (string) $row['changeset_uuid'],
                'kind' => (string) $row['kind'],
                'source' => (string) $row['source'],
                'actor' => $this->objects->actor($row['actor_user_id']),
                'label' => $this->label($row),
            ];
            $items[] = $item;
        }

        return ['object' => $this->objects->describe($ref, $userId), 'items' => $items, 'next_cursor' => $next];
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    public function presentChange(array $change): array
    {
        $adapter = $this->adapters->find((string) $change['adapter_key']);
        $field = (string) $change['field_key'];

        return [
            'id' => (int) $change['id'],
            'event' => (string) $change['event'],
            'field' => $field,
            'field_label' => $field === '' ? '' : ($adapter !== null ? $adapter->fieldLabel($field) : $field),
            'restorable' => (bool) (int) $change['restorable'],
            'reason_code' => $change['reason_code'] === null ? null : (string) $change['reason_code'],
            'quality' => (string) $change['quality'],
            'reverts_change_id' => $change['reverts_change_id'] === null ? null : (int) $change['reverts_change_id'],
            'created_at' => Time::iso($change['created_at']),
        ];
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{fields: list<string>, events: list<string>, changes: int, limited: int, objects: list<ObjectRef>}>
     */
    private function aggregate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_map('intval', $ids));
        $out = [];
        $stats = (array) $this->db->get_results(
            "SELECT changeset_id, GROUP_CONCAT(DISTINCT field_key) AS fields, GROUP_CONCAT(DISTINCT event) AS events,
                    COUNT(*) AS changes, SUM(restorable = 0 AND event IN ('update', 'restore')) AS limited
             FROM {$this->t->changes} WHERE changeset_id IN ({$in}) GROUP BY changeset_id",
            ARRAY_A
        );

        foreach ($stats as $s) {
            $out[(int) $s['changeset_id']] = [
                'fields' => array_values(array_filter(explode(',', (string) $s['fields']))),
                'events' => array_values(array_filter(explode(',', (string) $s['events']))),
                'changes' => (int) $s['changes'],
                'limited' => (int) $s['limited'],
                'objects' => [],
            ];
        }

        $objects = (array) $this->db->get_results(
            "SELECT changeset_id, object_type, object_id, MAX(object_subtype) AS object_subtype, MIN(id) AS first_id
             FROM {$this->t->changes} WHERE changeset_id IN ({$in})
             GROUP BY changeset_id, object_type, object_id ORDER BY first_id",
            ARRAY_A
        );

        foreach ($objects as $o) {
            if (isset($out[(int) $o['changeset_id']])) {
                $out[(int) $o['changeset_id']]['objects'][] = new ObjectRef((string) $o['object_type'], (int) $o['object_id'], (string) $o['object_subtype']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{fields: list<string>, events: list<string>, changes: int, limited: int, objects: list<ObjectRef>}|null $details
     *
     * @return array<string, mixed>|null Null when the viewer can see none of the objects.
     */
    private function present(array $row, ?array $details, int $userId, int $maxObjects): ?array
    {
        if ($details === null) {
            return null; // empty changeset (for example while being coalesced)
        }

        $visible = [];
        $hidden = 0;

        foreach ($details['objects'] as $object) {
            if (user_can($userId, 'sundo_view_object_history', $object->id, $object->subtype)) {
                $visible[] = $object;
            } else {
                $hidden++;
            }
        }

        if ($visible === []) {
            return null;
        }

        $job = null;

        if ($row['restore_job_id'] !== null) {
            $job = $this->db->get_var($this->db->prepare("SELECT uuid FROM {$this->t->jobs} WHERE id = %d", (int) $row['restore_job_id']));
        }

        $fields = [];

        foreach ($details['fields'] as $field) {
            $fields[] = ['key' => $field, 'label' => $this->fieldLabel($field)];
        }

        return [
            'id' => (string) $row['uuid'],
            'kind' => (string) $row['kind'],
            'source' => (string) $row['source'],
            'grouping' => (string) $row['grouping_mode'],
            'label' => $this->label($row),
            'status' => (string) $row['status'],
            'quality' => (string) $row['quality'],
            'actor' => $this->objects->actor($row['actor_user_id']),
            'created_at' => Time::iso($row['created_at']),
            'updated_at' => Time::iso($row['updated_at']),
            'change_count' => $details['changes'],
            'object_count' => count($visible),
            'hidden_objects' => $hidden,
            'fields' => $fields,
            'events' => $details['events'],
            'has_limitations' => $details['limited'] > 0 || $row['quality'] !== 'verified',
            'objects' => array_map(fn (ObjectRef $o): array => $this->objects->describe($o, $userId), array_slice($visible, 0, $maxObjects)),
            'restore_job_id' => $job !== null ? (string) $job : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function label(array $row): string
    {
        if (($row['label'] ?? '') !== '') {
            return (string) $row['label'];
        }

        if (!empty($row['operation_id'])) {
            return (string) $this->db->get_var($this->db->prepare("SELECT label FROM {$this->t->operations} WHERE id = %d", (int) $row['operation_id']));
        }

        return '';
    }

    private function fieldLabel(string $field): string
    {
        foreach ($this->adapters->all() as $adapter) {
            $label = $adapter->fieldLabel($field);

            if ($label !== $field) {
                return $label;
            }
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: string|null, 1: list<int|string>} SQL fragment (null = no match possible) and params.
     */
    private function where(array $filters): array
    {
        $sql = '';
        $params = [];

        if (isset($filters['actor'])) {
            $sql .= ' AND cs.actor_user_id = %d';
            $params[] = (int) $filters['actor'];
        }

        if (isset($filters['kind']) && $filters['kind'] !== '') {
            $sql .= ' AND cs.kind = %s';
            $params[] = (string) $filters['kind'];
        } else {
            // Autosave sessions are shown only when asked for explicitly or per object.
            $sql .= isset($filters['object_id']) ? '' : " AND cs.kind <> 'autosave'";
        }

        if (isset($filters['source']) && $filters['source'] !== '') {
            $sql .= ' AND cs.source = %s';
            $params[] = (string) $filters['source'];
        }

        if (isset($filters['from'])) {
            $from = Time::mysqlFromIso((string) $filters['from']);

            if ($from !== null) {
                $sql .= ' AND cs.created_at >= %s';
                $params[] = $from;
            }
        }

        if (isset($filters['to'])) {
            $to = Time::mysqlFromIso((string) $filters['to']);

            if ($to !== null) {
                $sql .= ' AND cs.created_at <= %s';
                $params[] = $to;
            }
        }

        $objectIds = null;

        if (isset($filters['object_id'])) {
            $objectIds = [(int) $filters['object_id']];
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $found = $this->searchObjects(trim((string) $filters['search']));
            $objectIds = $objectIds === null ? $found : array_values(array_intersect($objectIds, $found));
        }

        if ($objectIds !== null) {
            if ($objectIds === []) {
                return [null, []];
            }

            $sql .= " AND EXISTS (SELECT 1 FROM {$this->t->changes} c WHERE c.changeset_id = cs.id AND c.object_type = %s AND c.object_id IN ("
                . implode(',', array_fill(0, count($objectIds), '%d')) . '))';
            $params[] = (string) ($filters['object_type'] ?? 'post');
            array_push($params, ...$objectIds);
        }

        if (isset($filters['subtype']) && $filters['subtype'] !== '') {
            $sql .= " AND EXISTS (SELECT 1 FROM {$this->t->changes} c2 WHERE c2.changeset_id = cs.id AND c2.object_subtype = %s)";
            $params[] = (string) $filters['subtype'];
        }

        return [$sql, $params];
    }

    /**
     * Searches CURRENT titles and IDs only; historical content is never searched.
     *
     * @return list<int>
     */
    private function searchObjects(string $search): array
    {
        if (ctype_digit($search)) {
            return [(int) $search];
        }

        return array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT ID FROM {$this->db->posts} WHERE post_title LIKE %s AND post_type <> 'revision' ORDER BY ID DESC LIMIT 200",
            '%' . $this->db->esc_like($search) . '%'
        )));
    }

    private function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode('c1:' . $id), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): int
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($raw === false || !preg_match('/^c1:(\d{1,19})$/', $raw, $m)) {
            throw new DomainError('su_invalid_cursor', 400, __('Invalid page cursor.', 'selective-undo'));
        }

        return (int) $m[1];
    }
}
