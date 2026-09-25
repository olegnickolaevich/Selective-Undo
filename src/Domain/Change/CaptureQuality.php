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

    public function rank(): int
    {
        return match ($this) {
            self::Verified => 0,
            self::Observed => 1,
            self::Ambiguous => 2,
        };
    }
}
