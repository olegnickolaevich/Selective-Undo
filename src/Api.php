<?php

declare(strict_types=1);

namespace SelectiveUndo;

use SelectiveUndo\Application\Capture\OperationManagerInterface;
use SelectiveUndo\Bootstrap\Services;

/**
 * Public facade returned by selective_undo().
 *
 * @api
 */
final class Api
{
    public function __construct(private readonly Services $services)
    {
    }

    public function version(): string
    {
        return SELECTIVE_UNDO_API_VERSION;
    }

    public function operations(): OperationManagerInterface
    {
        return $this->services->operations();
    }
}
