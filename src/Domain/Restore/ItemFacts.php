<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

/**
 * Facts collected for one (object, field) pair while building a plan.
 * Everything that needs WordPress or the database is computed outside;
 * the evaluation itself is a pure function.
 */
final readonly class ItemFacts
{
    /**
     * @param list<string> $blockingReasons Write restrictions from the adapter and from the
     *                                      selective_undo/restore_preconditions filter.
     * @param list<string> $warnings        Non-fatal warnings.
     */
    public function __construct(
        public bool $supported,
        public bool $objectExists,
        public bool $authorized,
        public ResolvedChain|ChainProblem $chain,
        public ?string $targetPayloadProblem,
        public ?string $currentHash,
        public array $blockingReasons = [],
        public array $warnings = [],
        public ?string $unsupportedReason = null,
    ) {
    }
}
