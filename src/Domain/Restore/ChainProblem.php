<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

final readonly class ChainProblem
{
    /**
     * @param list<int> $changeIds
     */
    public function __construct(
        public string $reasonCode,
        public array $changeIds,
    ) {
    }
}
