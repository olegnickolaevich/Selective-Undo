<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

/** A statement on the restore connection failed. The message is a code, never data. */
class DbError extends \RuntimeException
{
    public const DUPLICATE_KEY = 1062;

    public function __construct(public readonly string $errorCode, int $errno = 0, ?\Throwable $previous = null)
    {
        parent::__construct($errorCode, $errno, $previous);
    }

    public function isDuplicateKey(): bool
    {
        return $this->getCode() === self::DUPLICATE_KEY;
    }
}
