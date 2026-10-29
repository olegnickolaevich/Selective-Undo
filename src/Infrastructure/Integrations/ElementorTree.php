<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Integrations;

use SelectiveUndo\Domain\Merge\LinkedOrder;

/**
 * Elementor's element tree (the decoded _elementor_data list) as a map keyed by
 * element ID, so that an undo can work element by element and setting by setting:
 *
 *   id => ['parent' => parent ID or '', 'after' => previous sibling ID or '', 'element' => element without children]
 *
 * Order is kept as "comes after", not as an index: inserting an element changes the
 * neighbour of one element, not the position of every following one.
 */
final class ElementorTree
{
    /**
     * @param list<mixed> $elements
     *
     * @return array<string, array{parent: string, after: string, element: array<array-key, mixed>}>|null
     *         Null when the tree is not a list of elements with unique IDs.
     */
    public static function flatten(array $elements): ?array
    {
        $map = [];

        return self::walk($elements, '', $map) ? $map : null;
    }

    /**
     * @param array<array-key, mixed> $map
     *
     * @return list<array<array-key, mixed>>
     */
    public static function build(array $map): array
    {
        $children = [];

        foreach ($map as $id => $node) {
            if (!is_array($node) || !is_array($node['element'] ?? null)) {
                continue;
            }

            $parent = (string) ($node['parent'] ?? '');
            // A child whose parent no longer exists is kept at the top level instead of lost.
            $parent = $parent !== '' && !isset($map[$parent]) ? '' : $parent;
            $children[$parent][(string) $id] = (string) ($node['after'] ?? '');
        }

        return self::level($map, $children, '');
    }

    /**
     * @param list<mixed> $elements
     * @param array<string, array{parent: string, after: string, element: array<array-key, mixed>}> $map
     */
    private static function walk(array $elements, string $parent, array &$map): bool
    {
        $after = '';

        foreach ($elements as $element) {
            if (!is_array($element) || !isset($element['id']) || !is_scalar($element['id'])) {
                return false;
            }

            $id = (string) $element['id'];

            if ($id === '' || isset($map[$id])) {
                return false;
            }

            $inner = $element['elements'] ?? [];
            unset($element['elements']);
            $map[$id] = ['parent' => $parent, 'after' => $after, 'element' => $element];

            if (!is_array($inner) || !self::walk(array_values($inner), $id, $map)) {
                return false;
            }

            $after = $id;
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $map
     * @param array<string, array<string, string>> $children parent => [child => after]
     *
     * @return list<array<array-key, mixed>>
     */
    private static function level(array $map, array $children, string $parent): array
    {
        $out = [];

        foreach (LinkedOrder::order($children[$parent] ?? []) as $id) {
            $element = $map[$id]['element'];
            $element['elements'] = self::level($map, $children, $id);
            $out[] = $element;
        }

        return $out;
    }
}
