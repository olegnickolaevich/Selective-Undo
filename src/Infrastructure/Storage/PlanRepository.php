<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Infrastructure\Database\StorageError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Restore plans. A plan is written as "preparing" and switched to "ready" only
 * after all items exist, so a crash never leaves a partial ready plan.
 */
final class PlanRepository
{
    public const TTL_SECONDS = 600;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
    ) {
    }

    /**
     * @param array<string, mixed> $selection
     *
     * @return array{id: int, uuid: string}
     */
    public function create(int $actorUserId, array $selection): array
    {
        $uuid = wp_generate_uuid4();
        $now = time();
        $ok = $this->db->insert($this->t->plans, [
            'uuid' => $uuid,
            'blog_id' => get_current_blog_id(),
            'actor_user_id' => $actorUserId,
            'status' => 'preparing',
            'selection_json' => (string) wp_json_encode($selection),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + self::TTL_SECONDS),
        ]);

        if ($ok === false) {
            throw new StorageError('plan_insert_failed');
        }

        return ['id' => (int) $this->db->insert_id, 'uuid' => $uuid];
    }

    /**
     * @param array{object_type: string, object_subtype: string, object_id: int, adapter_key: string,
     *              adapter_version: int, field_key: string, first_change_id: int, last_change_id: int,
     *              chain_length: int, expected_hash: string|null, target_hash: string|null,
     *              target_blob_id: int|null, status: string, reason_code: string|null, warnings: list<string>} $item
     * @param list<int> $sourceChangeIds
     */
    public function addItem(int $planId, array $item, array $sourceChangeIds): int
    {
        $row = $item;
        $row['plan_id'] = $planId;
        $row['warnings'] = implode(',', array_unique($item['warnings']));
        $row = array_filter($row, static fn ($v): bool => $v !== null);

        if ($this->db->insert($this->t->planItems, $row) === false) {
            throw new StorageError('plan_item_insert_failed');
        }

        $itemId = (int) $this->db->insert_id;

        if ($sourceChangeIds !== []) {
            $values = implode(', ', array_map(
                fn (int $changeId): string => $this->db->prepare('(%d, %d)', $itemId, $changeId),
                array_values(array_unique($sourceChangeIds))
            ));

            if ($this->db->query("INSERT INTO {$this->t->planItemSources} (plan_item_id, change_id) VALUES {$values}") === false) {
                throw new StorageError('plan_source_insert_failed');
            }
        }

        return $itemId;
    }

    /**
     * @param array{item_count: int, ready_count: int, object_count: int} $counts
     */
    public function markReady(int $planId, array $counts, string $planHash): void
    {
        $ok = $this->db->update(
            $this->t->plans,
            [
                'status' => 'ready',
                'item_count' => $counts['item_count'],
                'ready_count' => $counts['ready_count'],
                'object_count' => $counts['object_count'],
                'plan_hash' => $planHash,
            ],
            ['id' => $planId, 'status' => 'preparing']
        );

        if ($ok !== 1) {
            throw new StorageError('plan_finalize_failed');
        }
    }

    public function markFailed(int $planId): void
    {
        $this->db->update($this->t->plans, ['status' => 'failed'], ['id' => $planId, 'status' => 'preparing']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t->plans} WHERE uuid = %s", $uuid), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t->plans} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $planId): array
    {
        return (array) $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t->planItems} WHERE plan_id = %d ORDER BY id",
            $planId
        ), ARRAY_A);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function item(int $planId, int $itemId): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->t->planItems} WHERE plan_id = %d AND id = %d",
            $planId,
            $itemId
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, list<int>> plan item id => source change ids
     */
    public function sources(int $planId): array
    {
        $rows = (array) $this->db->get_results($this->db->prepare(
            "SELECT s.plan_item_id, s.change_id FROM {$this->t->planItemSources} s
             JOIN {$this->t->planItems} i ON i.id = s.plan_item_id
             WHERE i.plan_id = %d ORDER BY s.change_id",
            $planId
        ), ARRAY_A);
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['plan_item_id']][] = (int) $row['change_id'];
        }

        return $out;
    }

    public function expire(int $planId): void
    {
        $this->db->query($this->db->prepare(
            "UPDATE {$this->t->plans} SET status = 'expired' WHERE id = %d AND status = 'ready'",
            $planId
        ));
    }

    /** Marks ready plans past their TTL and stuck "preparing" plans as expired. */
    public function expireStale(): int
    {
        return (int) $this->db->query(
            "UPDATE {$this->t->plans} SET status = 'expired'
             WHERE (status = 'ready' AND expires_at <= UTC_TIMESTAMP())
                OR (status = 'preparing' AND created_at <= UTC_TIMESTAMP() - INTERVAL 1 HOUR)"
        );
    }
}
