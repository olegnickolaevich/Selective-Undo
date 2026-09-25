<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

enum Comparison: string
{
    case Ready = 'ready';
    case AlreadyRestored = 'already_restored';
    case Conflict = 'conflict';
}
