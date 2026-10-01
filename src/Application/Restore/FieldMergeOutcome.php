<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Value\FieldValue;

/**
 * Result of undoing one change of a field that changed again later.
 */
final readonly class FieldMergeOutcome
{
    /**
     * @param list<array{index: int, label: string, current: string, restored: string}> $conflicts Undecided conflicts.
     * @param string|null $reason Why there is no value (merge not possible).
     */
    public function __construct(
        public ?FieldValue $value,
        public array $conflicts,
        public ?string $reason = null,
    ) {
    }

    public function isClean(): bool
    {
        return $this->value !== null && $this->conflicts === [];
    }
}
