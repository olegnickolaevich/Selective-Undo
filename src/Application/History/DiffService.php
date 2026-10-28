<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\History;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Application\Restore\EditorMergePreview;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\PresentsImages;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Domain\Merge\TwoWayDiff;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;
use SelectiveUndo\Infrastructure\WordPress\BlockTitles;

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

        $object = new ObjectRef((string) $row['object_type'], (int) $row['object_id'], (string) $row['object_subtype']);
        $adapterKey = (string) $row['adapter_key'];
        $field = (string) $row['field_key'];
        $rawBefore = $this->value($row['before_blob_id'], $row['before_hash']);
        $rawAfter = $this->value($row['after_blob_id'], $row['after_hash']);
        $isRestore = $row['event'] === 'restore';

        return $this->result(
            'before_after',
            $isRestore ? __('Before restore', 'selective-undo') : __('Before', 'selective-undo'),
            $isRestore ? __('After restore', 'selective-undo') : __('After', 'selective-undo'),
            $this->shown($adapterKey, $object, $field, $rawBefore),
            $this->shown($adapterKey, $object, $field, $rawAfter),
            $offset
        ) + $this->images($adapterKey, $object, $field, $rawBefore, $rawAfter);
    }

    /**
     * One change as numbered hunks of blocks (or paragraphs of classic text). The
     * hunk numbers are what "undo only this part" refers to.
     *
     * @return array{available: bool, hunks: list<array<string, mixed>>}
     *
     * @throws DomainError
     */
    public function changeBlocks(int $changeId, int $userId): array
    {
        $row = $this->journal->changesByIds([$changeId])[$changeId] ?? null;

        if ($row === null || !user_can($userId, 'sundo_view_object_history', (int) $row['object_id'], (string) $row['object_subtype'])) {
            throw new DomainError('su_not_found', 404, __('This change no longer exists in the history.', 'selective-undo'));
        }

        $before = $this->value($row['before_blob_id'], $row['before_hash'])?->value;
        $after = $this->value($row['after_blob_id'], $row['after_hash'])?->value;
        $a = is_string($before) && is_string($after) ? EditorMergePreview::tokens($after, $before) : null;
        $b = is_string($before) && is_string($after) ? EditorMergePreview::tokens($before, $after) : null;
        $diff = $a !== null && $b !== null ? TwoWayDiff::hunks($a, $b) : null;

        if ($diff === null) {
            return ['available' => false, 'hunks' => []];
        }

        $hunks = [];

        foreach ($diff as $hunk) {
            $hunks[] = $hunk['kind'] === 'same'
                ? ['kind' => 'same', 'blocks' => count(self::blocks($hunk['tokens']))]
                : ['kind' => 'change', 'index' => $hunk['index'], 'before' => self::blocks($hunk['before']), 'after' => self::blocks($hunk['after'])];
        }

        return ['available' => true, 'hunks' => $hunks];
    }

    /**
     * Blocks of a hunk without the blank text between them.
     *
     * @param list<string> $tokens
     *
     * @return list<array{name: string, label: string, text: string, html: string}>
     */
    private static function blocks(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $token) {
            if (trim($token) === '') {
                continue;
            }

            $name = BlockTitles::nameOf($token);
            $out[] = [
                'name' => $name,
                'label' => BlockTitles::title($name),
                'text' => trim(wp_strip_all_tags($token)),
                'html' => $token,
            ];
        }

        return $out;
    }

    /**
     * Values before and after one change, for the front-end highlight. A value that is
     * not text (missing, a list) is null. Null when the change is unknown or not visible
     * to the user.
     *
     * @return array{object_id: int, field: string, before: string|null, after: string|null}|null
     */
    public function changeValues(int $changeId, int $userId): ?array
    {
        $row = $this->journal->changesByIds([$changeId])[$changeId] ?? null;

        if ($row === null || (string) $row['field_key'] === ''
            || !user_can($userId, 'sundo_view_object_history', (int) $row['object_id'], (string) $row['object_subtype'])) {
            return null;
        }

        $before = $this->value($row['before_blob_id'], $row['before_hash'])?->value;
        $after = $this->value($row['after_blob_id'], $row['after_hash'])?->value;

        return [
            'object_id' => (int) $row['object_id'],
            'field' => (string) $row['field_key'],
            'before' => is_string($before) ? $before : null,
            'after' => is_string($after) ? $after : null,
        ];
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
        $adapterKey = (string) $item['adapter_key'];
        $field = (string) $item['field_key'];
        $adapter = $this->adapters->find($adapterKey);
        $snapshot = $adapter?->read($object);
        $rawCurrent = $snapshot?->field($field) ?? FieldValue::missing();
        $rawTarget = $this->value($item['target_blob_id'], $item['target_hash']);
        $current = $this->shown($adapterKey, $object, $field, $rawCurrent);
        $target = $this->shown($adapterKey, $object, $field, $rawTarget);

        if ($view === 'conflict') {
            $last = $this->journal->changesByIds([(int) $item['last_change_id']])[(int) $item['last_change_id']] ?? null;
            $expected = $last !== null ? $this->shown($adapterKey, $object, $field, $this->value($last['after_blob_id'], $last['after_hash'])) : null;

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
        ) + $this->images($adapterKey, $object, $field, $rawCurrent, $rawTarget);
    }

    /**
     * What a comparison shows for a stored value: term names instead of term IDs,
     * a file name instead of an attachment ID. Never used to compare values.
     */
    private function shown(string $adapterKey, ObjectRef $object, string $field, ?FieldValue $value): ?FieldValue
    {
        $adapter = $this->adapters->find($adapterKey);

        if ($value === null || !$adapter instanceof PresentsValues) {
            return $value;
        }

        return FieldValue::of($adapter->present($object, $field, $value));
    }

    /**
     * Previews of media library images the two values refer to (a featured image).
     *
     * @return array{images?: array{left: string|null, right: string|null}}
     */
    private function images(string $adapterKey, ObjectRef $object, string $field, ?FieldValue $left, ?FieldValue $right): array
    {
        $adapter = $this->adapters->find($adapterKey);

        if (!$adapter instanceof PresentsImages) {
            return [];
        }

        $images = [
            'left' => $left !== null ? $adapter->imageUrl($object, $field, $left) : null,
            'right' => $right !== null ? $adapter->imageUrl($object, $field, $right) : null,
        ];

        return $images['left'] === null && $images['right'] === null ? [] : ['images' => $images];
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
