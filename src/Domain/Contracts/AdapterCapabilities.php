<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

final readonly class AdapterCapabilities
{
    /**
     * @param list<string> $fields
     * @param list<string> $knownSideEffects
     */
    public function __construct(
        public array $fields,
        public bool $requiresTransactions,
        public bool $supportsBackground,
        public bool $hasLinkedData,
        public array $knownSideEffects,
        public bool $idempotent,
        public ?int $maxValueBytes,
    ) {
    }
}
