<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Value\FieldValue;

/** Current state of an object expressed in adapter fields. */
interface ObjectSnapshot
{
    public function objectId(): int;

    public function objectSubtype(): string;

    public function field(string $fieldKey): FieldValue;
}
