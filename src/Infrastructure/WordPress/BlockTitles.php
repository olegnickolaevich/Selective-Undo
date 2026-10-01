<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

/**
 * Names of blocks as the user sees them: the registered title, translated by
 * WordPress ("Paragraph", «Абзац»), or the block name when the block is unknown.
 */
final class BlockTitles
{
    /**
     * @param string $name Name from block markup: "paragraph", "core/heading", "acme/card"; "" for classic text.
     */
    public static function title(string $name): string
    {
        if ($name === '') {
            return __('Text', 'selective-undo');
        }

        $full = str_contains($name, '/') ? $name : 'core/' . $name;
        $type = \WP_Block_Type_Registry::get_instance()->get_registered($full);

        return $type !== null && is_string($type->title) && $type->title !== '' ? $type->title : $name;
    }

    /** Name of the block a piece of markup starts with, "" for classic text. */
    public static function nameOf(string $markup): string
    {
        return preg_match('/^\s*<!--\s+wp:([a-z0-9_\/-]+)/', $markup, $m) === 1 ? $m[1] : '';
    }
}
