<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Merge\ContentMerger;
use SelectiveUndo\Domain\Merge\KeyedMerger;
use SelectiveUndo\Domain\Merge\MergeConflict;
use SelectiveUndo\Domain\Merge\MergeStrategy;
use SelectiveUndo\Domain\Merge\SetMerger;
use SelectiveUndo\Domain\Value\FieldValue;

/**
 * Undo of one change of a field that changed again later: keeps the later edits of
 * other parts. Base is the value right after the change, "restored" the value
 * right before it.
 */
final class FieldMerger
{
    public function __construct(private readonly ContentMerger $content = new ContentMerger())
    {
    }

    /**
     * @param array<int, 'current'|'restored'> $resolutions Conflict index => chosen side.
     */
    public function undo(MergeStrategy $strategy, FieldValue $base, FieldValue $current, FieldValue $restored, array $resolutions = []): FieldMergeOutcome
    {
        return match ($strategy) {
            MergeStrategy::Replace => new FieldMergeOutcome(null, [], 'merge_not_supported'),
            MergeStrategy::Segments => $this->segments($base, $current, $restored, $resolutions),
            MergeStrategy::Set => $this->set($base, $current, $restored),
            MergeStrategy::Keys => $this->keys($base, $current, $restored),
        };
    }

    /**
     * @param array<int, 'current'|'restored'> $resolutions
     */
    private function segments(FieldValue $base, FieldValue $current, FieldValue $restored, array $resolutions): FieldMergeOutcome
    {
        if (!is_string($base->value) || !is_string($current->value) || !is_string($restored->value)) {
            return new FieldMergeOutcome(null, [], 'merge_type_mismatch');
        }

        $result = $this->content->undo($base->value, $current->value, $restored->value, $resolutions);

        if ($result === null) {
            return new FieldMergeOutcome(null, [], 'merge_too_complex');
        }

        $open = [];

        foreach ($result->conflicts as $conflict) {
            // Conflicts the user already decided on are not conflicts any more.
            if (!isset($resolutions[$conflict->index])) {
                $open[] = self::describe($conflict);
            }
        }

        return new FieldMergeOutcome(FieldValue::of($result->value), $open);
    }

    /**
     * @return array{index: int, label: string, current: string, restored: string}
     */
    private static function describe(MergeConflict $conflict): array
    {
        $current = implode('', $conflict->current);
        $restored = implode('', $conflict->restored);

        $label = self::label($current !== '' ? $current : $restored);

        return [
            'index' => $conflict->index,
            'label' => $label === 'text' && $conflict->block !== '' ? $conflict->block : $label,
            'current' => $current,
            'restored' => $restored,
        ];
    }

    private function set(FieldValue $base, FieldValue $current, FieldValue $restored): FieldMergeOutcome
    {
        if (!self::isList($base->value) || !self::isList($current->value) || !self::isList($restored->value)) {
            return new FieldMergeOutcome(null, [], 'merge_type_mismatch');
        }

        return new FieldMergeOutcome(FieldValue::of(SetMerger::undo($base->value, $current->value, $restored->value)), []);
    }

    private function keys(FieldValue $base, FieldValue $current, FieldValue $restored): FieldMergeOutcome
    {
        if (!is_array($base->value) || !is_array($current->value) || !is_array($restored->value)) {
            return new FieldMergeOutcome(null, [], 'merge_type_mismatch');
        }

        $paths = [];
        $merged = KeyedMerger::undo($base->value, $current->value, $restored->value, $paths);
        $conflicts = [];

        foreach ($paths as $i => $path) {
            $conflicts[] = ['index' => $i, 'label' => implode(' › ', array_map('strval', $path)), 'current' => '', 'restored' => ''];
        }

        return new FieldMergeOutcome(FieldValue::of($merged), $conflicts);
    }

    /**
     * @phpstan-assert-if-true list<int|string> $value
     */
    private static function isList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $member) {
            if (!is_int($member) && !is_string($member)) {
                return false;
            }
        }

        return true;
    }

    /** Block name of a segment ("paragraph", "core/heading"), or "text" for classic text. */
    private static function label(string $segment): string
    {
        return preg_match('/^\s*<!--\s+wp:([a-z0-9_\/-]+)/', $segment, $m) === 1 ? $m[1] : 'text';
    }
}
