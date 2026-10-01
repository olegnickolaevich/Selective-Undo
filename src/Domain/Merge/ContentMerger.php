<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Undo of one change of post content that changed again later: diff3 over
 * top-level blocks (or paragraphs of classic text), recursing into blocks whose
 * wrapper did not change. Unchanged parts keep every byte.
 */
final class ContentMerger
{
    private const MAX_DEPTH = 8;

    public function __construct(private readonly BlockSegmenter $segmenter = new BlockSegmenter())
    {
    }

    /**
     * @param string $afterChange  Value right after the change being undone (merge base).
     * @param string $current      Value now.
     * @param string $beforeChange Value right before the change (what the undo restores).
     * @param array<int, 'current'|'restored'> $resolutions Conflict index => chosen side.
     */
    public function undo(string $afterChange, string $current, string $beforeChange, array $resolutions = []): ?MergeResult
    {
        $conflicts = [];
        $value = $this->merge($afterChange, $current, $beforeChange, $resolutions, $conflicts, 0, '');

        return $value === null ? null : new MergeResult($value, $conflicts, $current !== $afterChange);
    }

    /**
     * @param array<int, string> $resolutions
     * @param list<MergeConflict> $conflicts
     */
    private function merge(string $base, string $current, string $restored, array $resolutions, array &$conflicts, int $depth, string $block): ?string
    {
        $tokens = $this->tokens([$base, $current, $restored]);

        if ($tokens === null) {
            return null;
        }

        $hunks = Diff3::hunks(...$tokens);

        if ($hunks === null) {
            return null;
        }

        $out = '';

        foreach ($hunks as $hunk) {
            if ($hunk['kind'] === 'take') {
                $out .= implode('', $hunk['tokens']);
                continue;
            }

            $nested = $depth < self::MAX_DEPTH ? $this->mergeContainer($hunk, $resolutions, $conflicts, $depth) : null;

            if ($nested !== null) {
                $out .= $nested;
                continue;
            }

            $index = count($conflicts);
            $conflicts[] = new MergeConflict($index, $hunk['base'], $hunk['current'], $hunk['restored'], $block);
            $out .= implode('', ($resolutions[$index] ?? 'current') === 'restored' ? $hunk['restored'] : $hunk['current']);
        }

        return $out;
    }

    /**
     * One block on every side, same opening and closing delimiter: merge its inner markup.
     *
     * @param array{base: list<string>, current: list<string>, restored: list<string>} $hunk
     * @param array<int, string> $resolutions
     * @param list<MergeConflict> $conflicts
     */
    private function mergeContainer(array $hunk, array $resolutions, array &$conflicts, int $depth): ?string
    {
        if (count($hunk['base']) !== 1 || count($hunk['current']) !== 1 || count($hunk['restored']) !== 1) {
            return null;
        }

        $parts = array_map(fn (string $s): ?array => $this->segmenter->unwrap($s), [$hunk['base'][0], $hunk['current'][0], $hunk['restored'][0]]);

        if (in_array(null, $parts, true) || $parts[0][0] !== $parts[1][0] || $parts[0][0] !== $parts[2][0]
            || $parts[0][2] !== $parts[1][2] || $parts[0][2] !== $parts[2][2]) {
            return null;
        }

        $mark = count($conflicts);
        $name = preg_match('/^<!--\s+wp:([a-z0-9_\/-]+)/', $parts[0][0], $m) === 1 ? $m[1] : '';
        $inner = $this->merge($parts[0][1], $parts[1][1], $parts[2][1], $resolutions, $conflicts, $depth + 1, $name);

        if ($inner === null) {
            array_splice($conflicts, $mark);

            return null;
        }

        return $parts[0][0] . $inner . $parts[0][2];
    }

    /**
     * @param array{0: string, 1: string, 2: string} $values
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}|null
     */
    private function tokens(array $values): ?array
    {
        $isBlocks = false;

        foreach ($values as $v) {
            $isBlocks = $isBlocks || str_contains($v, '<!-- wp:');
        }

        $out = [];

        foreach ($values as $v) {
            $t = $isBlocks ? $this->segmenter->segments($v) : self::paragraphs($v);

            if ($t === null) {
                return null;
            }

            $out[] = $t;
        }

        return [$out[0], $out[1], $out[2]];
    }

    /**
     * Paragraphs and the blank lines between them as separate tokens.
     *
     * @return list<string>
     */
    public static function paragraphs(string $text): array
    {
        $parts = preg_split('/(\R{2,})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [$text] : $parts;
    }
}
