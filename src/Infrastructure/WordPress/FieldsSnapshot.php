<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Value\FieldValue;

/**
 * State of an object as fields read on demand (meta keys, taxonomies). Every
 * value is read once per snapshot.
 */
final class FieldsSnapshot implements ObjectSnapshot
{
    /** @var array<string, FieldValue> */
    private array $values = [];

    /**
     * @param \Closure(string): FieldValue $read field key => current value
     */
    public function __construct(
        private readonly int $id,
        private readonly string $subtype,
        private readonly \Closure $read,
        public readonly string $status = '',
    ) {
    }

    public function objectId(): int
    {
        return $this->id;
    }

    public function objectSubtype(): string
    {
        return $this->subtype;
    }

    public function field(string $fieldKey): FieldValue
    {
        return $this->values[$fieldKey] ??= ($this->read)($fieldKey);
    }
}
