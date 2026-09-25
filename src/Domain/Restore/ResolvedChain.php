<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

final readonly class ResolvedChain
{
    /**
     * @param list<int> $changeIds
     */
    public function __construct(
        public array $changeIds,
        public int $targetChangeId,
        public int $expectedChangeId,
        public string $targetHash,
        public string $expectedHash,
        public bool $hasInterveningChanges,
    ) {
    }
}
