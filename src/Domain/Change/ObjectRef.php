<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Change;

/**
 * Reference to a restorable object: type (post), ID and subtype (post type
 * at capture time). The site is implied by the table prefix.
 */
final readonly class ObjectRef
{
    public function __construct(
        public string $type,
        public int $id,
        public string $subtype = '',
    ) {
    }

    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }
}
