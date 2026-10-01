<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper), never printed as HTML.

use SelectiveUndo\Domain\Change\ChangeEvent;
use SelectiveUndo\Domain\Contracts\MergeableAdapter;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Domain\Merge\BlockSegmenter;
use SelectiveUndo\Domain\Merge\ContentMerger;
use SelectiveUndo\Domain\Merge\MergeStrategy;
use SelectiveUndo\Domain\Merge\TwoWayDiff;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalReader;

/**
 * Undo of one change against the editor's own, possibly unsaved, value. Writes
 * nothing: the editor applies the result and the user saves it. Restoring on the
 * server while the editor is open would be overwritten by the editor's next save.
 */
final class EditorMergePreview
{
    public const FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    public function __construct(
        private readonly JournalReader $journal,
        private readonly BlobStore $blobs,
        private readonly AdapterRegistry $adapters,
        private readonly FieldMerger $merger = new FieldMerger(),
    ) {
    }

    /**
     * @param array<int, 'current'|'restored'> $resolutions Conflict index => chosen side.
     * @param list<int> $hunks Undo only these hunks of the change; empty = the whole change.
     *
     * @return array{status: 'clean'|'conflict'|'already'|'unsupported', field: string, value?: string,
     *               conflicts?: list<array{index: int, label: string, current: string, restored: string}>}
     *
     * @throws DomainError
     */
    public function preview(int $userId, int $changeId, string $current, array $resolutions = [], array $hunks = []): array
    {
        $row = $this->journal->changesByIds([$changeId])[$changeId] ?? null;

        if ($row === null || $row['object_type'] !== 'post' || !ChangeEvent::from((string) $row['event'])->isFieldChange()) {
            throw new DomainError('su_not_found', 404, __('This change no longer exists in the history.', 'selective-undo'));
        }

        $postId = (int) $row['object_id'];
        $field = (string) $row['field_key'];

        // The user saves the result through the editor, so they must be able to edit the post.
        if (!user_can($userId, 'sundo_view_object_history', $postId, (string) $row['object_subtype'])
            || !user_can($userId, 'sundo_restore_object', $postId) || !user_can($userId, 'edit_post', $postId)) {
            throw new DomainError('su_forbidden', 403, __('You are not allowed to edit this item.', 'selective-undo'));
        }

        if (!in_array($field, self::FIELDS, true) || !(bool) (int) $row['restorable']) {
            return ['status' => 'unsupported', 'field' => $field];
        }

        try {
            $before = $this->blobs->loadVerified((int) $row['before_blob_id'], (string) $row['before_hash']);
            $after = $this->blobs->loadVerified((int) $row['after_blob_id'], (string) $row['after_hash']);
        } catch (InvalidPayload) {
            return ['status' => 'unsupported', 'field' => $field];
        }

        $adapter = $this->adapters->find((string) $row['adapter_key']);
        $strategy = $adapter instanceof MergeableAdapter ? $adapter->mergeStrategy($field) : MergeStrategy::Replace;
        $restored = $before;

        if ($hunks !== []) {
            $partial = $strategy === MergeStrategy::Segments ? self::partialRevert($after, $before, $hunks) : null;

            if ($partial === null) {
                return ['status' => 'unsupported', 'field' => $field];
            }

            $restored = $partial;
        }

        // Title, content and excerpt are text.
        $restoredText = $restored->value;

        if (!is_string($restoredText) || !is_string($after->value)) {
            return ['status' => 'unsupported', 'field' => $field];
        }

        $now = FieldValue::of($current);

        if ($now->equals($restored)) {
            return ['status' => 'already', 'field' => $field];
        }

        if ($now->equals($after)) {
            return ['status' => 'clean', 'field' => $field, 'value' => $restoredText];
        }

        $outcome = $this->merger->undo($strategy, $after, $now, $restored, $resolutions);

        if ($outcome->value === null) {
            // The whole field changed since (title, excerpt) or the text is too different:
            // one conflict for the whole field.
            return match ($resolutions[0] ?? null) {
                'restored' => ['status' => 'clean', 'field' => $field, 'value' => $restoredText],
                'current' => ['status' => 'already', 'field' => $field],
                default => ['status' => 'conflict', 'field' => $field, 'conflicts' => [[
                    'index' => 0,
                    'label' => $adapter !== null ? $adapter->fieldLabel($field) : $field,
                    'current' => $current,
                    'restored' => $restoredText,
                ]]],
            };
        }

        $merged = $outcome->value->value;

        return $outcome->isClean() && is_string($merged)
            ? ['status' => 'clean', 'field' => $field, 'value' => $merged]
            : ['status' => 'conflict', 'field' => $field, 'conflicts' => $outcome->conflicts];
    }

    /**
     * The value after the change with only some of its hunks reverted.
     *
     * @param list<int> $hunks
     */
    public static function partialRevert(FieldValue $after, FieldValue $before, array $hunks): ?FieldValue
    {
        if (!is_string($after->value) || !is_string($before->value)) {
            return null;
        }

        $a = self::tokens($after->value, $before->value);
        $b = self::tokens($before->value, $after->value);
        $value = $a !== null && $b !== null ? TwoWayDiff::partialRevert($a, $b, $hunks) : null;

        return $value === null ? null : FieldValue::of($value);
    }

    /**
     * Blocks, or paragraphs when neither side has blocks.
     *
     * @return list<string>|null
     */
    public static function tokens(string $value, string $other): ?array
    {
        return str_contains($value, '<!-- wp:') || str_contains($other, '<!-- wp:')
            ? (new BlockSegmenter())->segments($value)
            : ContentMerger::paragraphs($value);
    }
}
