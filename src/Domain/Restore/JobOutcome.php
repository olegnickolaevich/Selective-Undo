<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

/**
 * The final job status is derived from item statuses only.
 */
final class JobOutcome
{
    public function resolve(JobCounters $c, bool $cancelRequested): ?JobStatus
    {
        if ($c->pending > 0) {
            return null;
        }

        $applied = $c->restored + $c->alreadyRestored;

        return match (true) {
            $cancelRequested && $c->cancelled > 0 => JobStatus::Cancelled,
            $c->failed > 0 && $applied > 0 => JobStatus::PartiallyFailed,
            $c->failed > 0 => JobStatus::Failed,
            $c->conflict > 0 || $c->skipped > 0 => JobStatus::CompletedWithConflicts,
            default => JobStatus::Completed,
        };
    }
}
