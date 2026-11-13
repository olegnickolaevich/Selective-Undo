<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

defined('ABSPATH') || exit;

enum Comparison: string
{
    case Ready = 'ready';
    case AlreadyRestored = 'already_restored';
    case Conflict = 'conflict';
}
