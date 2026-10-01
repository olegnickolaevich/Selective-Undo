<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Two-way diff of token lists as numbered hunks. Hunk numbers are the stable
 * handle the interface uses to undo only some blocks of one change.
 */
final class TwoWayDiff
{
    /**
     * @param list<string> $a Tokens after the change.
     * @param list<string> $b Tokens before the change.
     *
     * @return list<array{kind: 'same', tokens: list<string>}|array{kind: 'change', index: int, after: list<string>, before: list<string>}>|null
     */
    public static function hunks(array $a, array $b): ?array
    {
        $matches = Lcs::matches($a, $b);

        if ($matches === null) {
            return null;
        }

        /** @var list<array{kind: 'same', tokens: list<string>}|array{kind: 'change', index: int, after: list<string>, before: list<string>}> $hunks */
        $hunks = [];
        $same = [];
        $i = $j = 0;
        $index = 0;
        $matches[count($a)] = count($b); // sentinel: flush the tail

        foreach ($matches as $x => $y) {
            if ($x > $i || $y > $j) {
                if ($same !== []) {
                    $hunks[] = ['kind' => 'same', 'tokens' => $same];
                    $same = [];
                }

                $hunks[] = [
                    'kind' => 'change',
                    'index' => $index++,
                    'after' => array_slice($a, $i, $x - $i),
                    'before' => array_slice($b, $j, $y - $j),
                ];
            }

            if ($x < count($a)) {
                $same[] = $a[$x];
            }

            $i = $x + 1;
            $j = $y + 1;
        }

        if ($same !== []) {
            $hunks[] = ['kind' => 'same', 'tokens' => $same];
        }

        return $hunks;
    }

    /**
     * The "after" value with only the given hunks reverted to "before".
     *
     * @param list<string> $a
     * @param list<string> $b
     * @param list<int> $revert Hunk indexes.
     */
    public static function partialRevert(array $a, array $b, array $revert): ?string
    {
        $hunks = self::hunks($a, $b);

        if ($hunks === null) {
            return null;
        }

        $out = '';

        foreach ($hunks as $h) {
            $out .= implode('', match (true) {
                $h['kind'] === 'same' => $h['tokens'],
                in_array($h['index'], $revert, true) => $h['before'],
                default => $h['after'],
            });
        }

        return $out;
    }
}
