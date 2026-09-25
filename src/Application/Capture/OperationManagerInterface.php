<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

/**
 * Public API: lets integrations mark the boundaries of their operations
 * (for example an import) so that the history shows a confirmed group.
 *
 * @api
 */
interface OperationManagerInterface
{
    /**
     * @param array<string, scalar|null> $metadata Scalars only, no content, at most 4 KB encoded.
     */
    public function begin(string $label, array $metadata = [], string $source = 'integration'): OperationToken;

    /** Continues an active operation in another request. */
    public function resume(string $operationId): OperationToken;

    public function finish(OperationToken $token): void;

    public function fail(OperationToken $token, string $reasonCode): void;
}
