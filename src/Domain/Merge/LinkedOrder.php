<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Order of siblings kept as "comes after" links instead of positions: inserting an
 * element changes the neighbour of one element, not the position of every following
 * one, so an undo merged key by key rarely conflicts on order.
 */
final class LinkedOrder
{
    /**
     * Follows the links; elements whose neighbour is gone, or that two edits put after
     * the same one, are placed after the chain in a stable order.
     *
     * @param array<array-key, string> $after element => previous sibling ('' for the first)
     *
     * @return list<string>
     */
    public static function order(array $after): array
    {
        ksort($after, SORT_STRING);
        $placed = [];
        $last = '';

        while ($after !== []) {
            $next = array_search($last, $after, true);

            if ($next === false) {
                // The chain is broken here: continue with an element whose neighbour is not waiting.
                $next = array_key_first(array_filter($after, static fn (string $prev): bool => !isset($after[$prev])));
                $next ??= array_key_first($after);
            }

            $next = (string) $next;
            $placed[] = $next;
            unset($after[$next]);
            $last = $next;
        }

        return $placed;
    }
}
