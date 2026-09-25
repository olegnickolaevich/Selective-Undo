<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\History;

use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;

/**
 * Diffs for a journal change (before / after) and for plan items
 * (current / will be restored, and the three states of a conflict).
 */
final class DiffService
{
    public function __construct(
        private readonly JournalReader $journal,
        private readonly PlanRepository $plans,
        private readonly BlobStore $blobs,
        private readonly AdapterRegistry $adapters,
        private readonly DiffBuilder $builder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function change(int $changeId, int $userId, int $offset): array
    {
        $row = $this->journal->changesByIds([$changeId])[$changeId] ?? null;

        if ($row === null || !user_can($userId, 'sundo_view_object_history', (int) $row['object_id'], (string) $row['object_subtype'])) {
            throw new DomainError('su_not_found', 404, __('This change no longer exists in the history.', 'selective-undo'));
        }

        if ((string) $row['field_key'] === '') {
            throw new DomainError('su_invalid_selection', 400, __('Informational events have no values to compare.', 'selective-undo'));
        }

        $before = $this->value($row['before_blob_id'], $row['before_hash']);
        $after = $this->value($row['after_blob_id'], $row['after_hash']);
        $isRestore = $row['event'] === 'restore';

        return $this->result(
            'before_after',
            $isRestore ? __('Before restore', 'selective-undo') : __('Before', 'selective-undo'),
            $isRestore ? __('After restore', 'selective-undo') : __('After', 'selective-undo'),
            $before,
            $after,
            $offset
        );
    }

    /**
     * @param 'current_target'|'conflict' $view
     *
     * @return array<string, mixed>
     */
    public function planItem(string $planUuid, int $itemId, int $userId, string $view, int $offset): array
    {
        $plan = $this->plans->findByUuid($planUuid);

        if ($plan === null || (int) $plan['actor_user_id'] !== $userId) {
            throw new DomainError('su_not_found', 404, __('This preview does not exist.', 'selective-undo'));
        }

        $item = $this->plans->item((int) $plan['id'], $itemId);

        if ($item === null || $item['target_blob_id'] === null) {
            throw new DomainError('su_not_found', 404, __('Nothing to compare for this item.', 'selective-undo'));
        }

        $object = new ObjectRef((string) $item['object_type'], (int) $item['object_id'], (string) $item['object_subtype']);
        $adapter = $this->adapters->find((string) $item['adapter_key']);
        $snapshot = $adapter?->read($object);
        $current = $snapshot?->field((string) $item['field_key']) ?? FieldValue::missing();
        $target = $this->value($item['target_blob_id'], $item['target_hash']);

        if ($view === 'conflict') {
            $last = $this->journal->changesByIds([(int) $item['last_change_id']])[(int) $item['last_change_id']] ?? null;
            $expected = $last !== null ? $this->value($last['after_blob_id'], $last['after_hash']) : null;

            return $this->result(
                'conflict',
                __('After the selected change', 'selective-undo'),
                __('Current value', 'selective-undo'),
                $expected,
                $current,
                $offset
            );
        }

        return $this->result(
            'current_target',
            __('Current value', 'selective-undo'),
            __('Will be restored', 'selective-undo'),
            $current,
            $target,
            $offset
        );
    }

    private function value(mixed $blobId, mixed $hash): ?FieldValue
    {
        if ($blobId === null || $hash === null) {
            return null;
        }

        try {
            return $this->blobs->loadVerified((int) $blobId, (string) $hash);
        } catch (InvalidPayload) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $mode, string $leftLabel, string $rightLabel, ?FieldValue $left, ?FieldValue $right, int $offset): array
    {
        $unavailable = $left === null || $right === null;
        $diff = $unavailable
            ? ['lines' => [], 'truncated' => false, 'next_offset' => null, 'binary' => false, 'total_lines' => 0]
            : $this->builder->build($left, $right, $offset);

        return ['mode' => $mode, 'left_label' => $leftLabel, 'right_label' => $rightLabel, 'unavailable' => $unavailable] + $diff;
    }
}
