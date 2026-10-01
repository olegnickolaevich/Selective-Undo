<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Undo of a change of a JSON object, key by key and recursively; lists are values.
 */
final class KeyedMerger
{
    private const MISSING = "\0missing";

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $current
     * @param array<array-key, mixed> $restored
     * @param list<list<array-key>> $conflicts Paths of conflicting keys (output).
     * @param list<array-key> $path Path of these values inside the whole object.
     *
     * @return array<array-key, mixed>
     */
    public static function undo(array $base, array $current, array $restored, array &$conflicts = [], array $path = []): array
    {
        $out = [];
        $keys = array_unique(array_merge(array_keys($current), array_keys($base), array_keys($restored)));

        foreach ($keys as $key) {
            $o = array_key_exists($key, $base) ? $base[$key] : self::MISSING;
            $c = array_key_exists($key, $current) ? $current[$key] : self::MISSING;
            $r = array_key_exists($key, $restored) ? $restored[$key] : self::MISSING;

            if ($c === $o) {
                $v = $r;                      // only the undo touches it
            } elseif ($r === $o || $r === $c) {
                $v = $c;                      // later edit, or both agree
            } elseif (self::isMap($o) && self::isMap($c) && self::isMap($r)) {
                $v = self::undo($o, $c, $r, $conflicts, [...$path, $key]);
            } else {
                $conflicts[] = [...$path, $key];
                $v = $c;                      // keep the later edit until the user decides
            }

            if ($v !== self::MISSING) {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    private static function isMap(mixed $v): bool
    {
        return is_array($v) && $v !== [] && !array_is_list($v);
    }
}
