<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Restore\JobItemStatus;
use SelectiveUndo\Domain\Value\FieldValue;

final readonly class ItemOutcome
{
    /**
     * @param array<string, int|float|string|null> $item Locked job item joined with its plan item.
     */
    public function __construct(
        public array $item,
        public JobItemStatus $status,
        public ?string $reasonCode = null,
        public ?FieldValue $currentValue = null,
    ) {
    }
}
