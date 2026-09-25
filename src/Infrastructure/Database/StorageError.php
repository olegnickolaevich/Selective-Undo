<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

/** A journal read or write failed. The message is a code; it never contains data. */
final class StorageError extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
