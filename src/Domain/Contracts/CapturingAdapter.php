<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

/**
 * An adapter that hooks into WordPress to observe its own fields. Called once per
 * request, after all adapters are registered.
 *
 * @api
 */
interface CapturingAdapter
{
    public function registerCapture(CaptureSink $sink): void;
}
