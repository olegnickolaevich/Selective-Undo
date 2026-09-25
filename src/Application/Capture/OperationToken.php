<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

/**
 * Handle of an explicit operation. Pass $token->id between requests
 * (for example in a background job) and call OperationManager::resume().
 */
final readonly class OperationToken
{
    public function __construct(
        public string $id,
        public int $depth = 1,
    ) {
    }
}
