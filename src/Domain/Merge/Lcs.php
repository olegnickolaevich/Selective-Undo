<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Alignment of two token lists: Myers' O((N+M)·D) algorithm after trimming the
 * common prefix and suffix.
 */
final class Lcs
{
    /** Documents needing more edits than this are not aligned (merge falls back to the whole field). */
    public const MAX_EDITS = 1000;

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return array<int, int>|null Index in $a => matched index in $b, increasing; null when too different.
     */
    public static function matches(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);
        $prefix = 0;

        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        $map = $prefix > 0 ? array_combine(range(0, $prefix - 1), range(0, $prefix - 1)) : [];
        $middle = self::myers(
            array_slice($a, $prefix, $n - $prefix - $suffix),
            array_slice($b, $prefix, $m - $prefix - $suffix)
        );

        if ($middle === null) {
            return null;
        }

        foreach ($middle as $x => $y) {
            $map[$prefix + $x] = $prefix + $y;
        }

        for ($s = 0; $s < $suffix; $s++) {
            $map[$n - $suffix + $s] = $m - $suffix + $s;
        }

        ksort($map);

        return $map;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return array<int, int>|null
     */
    private static function myers(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0) {
            return [];
        }

        $v = [1 => 0];
        $trace = [];
        $found = false;

        for ($d = 0, $max = min($n + $m, self::MAX_EDITS); $d <= $max; $d++) {
            $trace[] = $v;

            for ($k = -$d; $k <= $d; $k += 2) {
                $x = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1)))
                    ? ($v[$k + 1] ?? 0)
                    : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;

                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }

                $v[$k] = $x;

                if ($x >= $n && $y >= $m) {
                    $found = true;
                    break 2;
                }
            }
        }

        if (!$found) {
            return null;
        }

        $matches = [];
        $x = $n;
        $y = $m;

        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            $prevK = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $x--;
                $y--;
                $matches[$x] = $y;
            }

            if ($d > 0) {
                $x = $prevX;
                $y = $prevY;
            }
        }

        ksort($matches);

        return $matches;
    }
}
