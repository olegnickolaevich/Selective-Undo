<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

final class StorageUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
