<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Value;

/** The value cannot be represented in the journal. */
final class InvalidFieldValue extends \DomainException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
