<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Three-way merge of token lists (Khanna, Kunal, Pierce: "A Formal Investigation of Diff3").
 * Base is the value after the change being undone, "current" the value now and
 * "restored" the value before the change.
 */
final class Diff3
{
    /**
     * @param list<string> $base
     * @param list<string> $current
     * @param list<string> $restored
     *
     * @return list<array{kind: 'take', tokens: list<string>}|array{kind: 'conflict', base: list<string>, current: list<string>, restored: list<string>}>|null
     *         Null when the input is too large to align.
     */
    public static function hunks(array $base, array $current, array $restored): ?array
    {
        $mc = Lcs::matches($base, $current);
        $mr = Lcs::matches($base, $restored);

        if ($mc === null || $mr === null) {
            return null;
        }

        $hunks = [];
        $i = $j = $k = 0;
        $n = count($base);

        while (true) {
            $stable = null;

            for ($o = $i; $o < $n; $o++) {
                if (isset($mc[$o], $mr[$o])) {
                    $stable = $o;
                    break;
                }
            }

            $end = $stable ?? $n;
            $cEnd = $stable === null ? count($current) : $mc[$stable];
            $rEnd = $stable === null ? count($restored) : $mr[$stable];
            $hunk = self::chunk(
                array_slice($base, $i, $end - $i),
                array_slice($current, $j, $cEnd - $j),
                array_slice($restored, $k, $rEnd - $k)
            );

            if ($hunk !== null) {
                $hunks[] = $hunk;
            }

            if ($stable === null) {
                return $hunks;
            }

            $hunks[] = ['kind' => 'take', 'tokens' => [$base[$stable]]];
            $i = $stable + 1;
            $j = $mc[$stable] + 1;
            $k = $mr[$stable] + 1;
        }
    }

    /**
     * @param list<string> $o
     * @param list<string> $c
     * @param list<string> $r
     *
     * @return array{kind: 'take', tokens: list<string>}|array{kind: 'conflict', base: list<string>, current: list<string>, restored: list<string>}|null
     */
    private static function chunk(array $o, array $c, array $r): ?array
    {
        return match (true) {
            $o === [] && $c === [] && $r === [] => null,
            $c === $o => ['kind' => 'take', 'tokens' => $r],   // only the undo touches it
            $r === $o, $r === $c => ['kind' => 'take', 'tokens' => $c], // only later edits, or both agree
            default => ['kind' => 'conflict', 'base' => $o, 'current' => $c, 'restored' => $r],
        };
    }
}
