<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case CancelRequested = 'cancel_requested';
    case Completed = 'completed';
    case CompletedWithConflicts = 'completed_with_conflicts';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Running, self::CancelRequested => false,
            default => true,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Queued => in_array($next, [self::Running, self::CancelRequested, self::Cancelled, self::Failed], true),
            self::Running => $next === self::CancelRequested || $next->isTerminal(),
            self::CancelRequested => $next->isTerminal(),
            default => false,
        };
    }
}
