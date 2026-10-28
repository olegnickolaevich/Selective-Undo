<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Change;

enum CaptureQuality: string
{
    /** Before and after were read directly from the database around the write. */
    case Verified = 'verified';
    /** Values come from WordPress hook arguments only. */
    case Observed = 'observed';
    /** Inconsistent observation: automatic restore is not allowed. */
    case Ambiguous = 'ambiguous';
    /** Found by comparing the database with the history: the value was changed outside WordPress. */
    case Reconciled = 'reconciled';

    public function rank(): int
    {
        return match ($this) {
            self::Verified, self::Reconciled => 0,
            self::Observed => 1,
            self::Ambiguous => 2,
        };
    }
}
