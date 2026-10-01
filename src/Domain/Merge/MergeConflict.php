<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * A part changed both by the undo and by later edits, differently.
 */
final readonly class MergeConflict
{
    /**
     * @param list<string> $base
     * @param list<string> $current
     * @param list<string> $restored
     * @param string $block Name of the block the conflict is inside ("" at the top level).
     */
    public function __construct(
        public int $index,
        public array $base,
        public array $current,
        public array $restored,
        public string $block = '',
    ) {
    }
}
