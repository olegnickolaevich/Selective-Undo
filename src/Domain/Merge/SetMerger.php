<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Undo of a change of a set (terms): later edits win for the members they touched.
 */
final class SetMerger
{
    /**
     * @param list<int|string> $base     Set right after the change.
     * @param list<int|string> $current  Set now.
     * @param list<int|string> $restored Set right before the change.
     *
     * @return list<int|string> Sorted result.
     */
    public static function undo(array $base, array $current, array $restored): array
    {
        $b = array_flip($base);
        $c = array_flip($current);
        $r = array_flip($restored);
        $all = array_keys($b + $c + $r);
        $out = [];

        foreach ($all as $member) {
            $inBase = isset($b[$member]);
            $inCurrent = isset($c[$member]);
            $inRestored = isset($r[$member]);
            // Later edits win for this member; otherwise the undo decides.
            $keep = $inCurrent !== $inBase ? $inCurrent : $inRestored;

            if ($keep) {
                $out[] = $member;
            }
        }

        sort($out);

        return $out;
    }
}
