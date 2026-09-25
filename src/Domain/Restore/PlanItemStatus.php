<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

enum PlanItemStatus: string
{
    case Ready = 'ready';
    case AlreadyRestored = 'already_restored';
    case Conflict = 'conflict';
    case MissingObject = 'missing_object';
    case Forbidden = 'forbidden';
    case Unsupported = 'unsupported';
    case Blocked = 'blocked';
}
