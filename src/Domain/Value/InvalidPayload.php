<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Value;

defined('ABSPATH') || exit;

/** A stored payload is corrupted, non-canonical or exceeds a limit. */
final class InvalidPayload extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
