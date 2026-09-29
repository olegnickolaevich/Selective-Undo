<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

interface LockService
{
    /** @return bool True when acquired within $waitSeconds. */
    public function acquire(string $name, int $waitSeconds): bool;

    public function release(string $name): void;
}
