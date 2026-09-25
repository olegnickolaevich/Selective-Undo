<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

/** A journal change of one field of one object, as seen by the chain resolver. */
final readonly class ChangeLink
{
    public function __construct(
        public int $changeId,
        public string $beforeHash,
        public string $afterHash,
        public bool $restorable,
        public ?string $reasonCode = null,
    ) {
    }
}
