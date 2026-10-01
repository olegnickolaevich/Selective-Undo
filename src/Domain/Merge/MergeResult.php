<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Merged text and the conflicts that were not decided (their current side is kept).
 */
final readonly class MergeResult
{
    /**
     * @param list<MergeConflict> $conflicts
     */
    public function __construct(
        public string $value,
        public array $conflicts,
        public bool $changedByOthers,
    ) {
    }

    public function isClean(): bool
    {
        return $this->conflicts === [];
    }
}
