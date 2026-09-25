<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain;

/**
 * Expected business error with a stable code. Mapped to REST errors and CLI output.
 */
final class DomainError extends \RuntimeException
{
    /**
     * @param array<string, scalar|null|array<array-key, scalar|null>> $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message = '',
        public readonly array $details = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
