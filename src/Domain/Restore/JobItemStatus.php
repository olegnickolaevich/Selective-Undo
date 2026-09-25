<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

enum JobItemStatus: string
{
    case Pending = 'pending';
    case Restored = 'restored';
    case AlreadyRestored = 'already_restored';
    case Conflict = 'conflict';
    /** Object disappeared, permission lost, write restriction or adapter unavailable. */
    case Skipped = 'skipped';
    /** Technical error after all attempts. */
    case Failed = 'failed';
    /** Not started because the job was stopped. */
    case Cancelled = 'cancelled';
}
