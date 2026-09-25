<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

final readonly class PlanItemDecision
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public PlanItemStatus $status,
        public ?string $reasonCode,
        public array $warnings,
    ) {
    }
}
