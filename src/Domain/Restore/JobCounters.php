<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

final readonly class JobCounters
{
    public function __construct(
        public int $pending = 0,
        public int $restored = 0,
        public int $alreadyRestored = 0,
        public int $conflict = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public int $cancelled = 0,
    ) {
    }

    /**
     * @param array<string, int> $byStatus job item status => count
     */
    public static function fromStatusCounts(array $byStatus): self
    {
        return new self(
            pending: $byStatus[JobItemStatus::Pending->value] ?? 0,
            restored: $byStatus[JobItemStatus::Restored->value] ?? 0,
            alreadyRestored: $byStatus[JobItemStatus::AlreadyRestored->value] ?? 0,
            conflict: $byStatus[JobItemStatus::Conflict->value] ?? 0,
            skipped: $byStatus[JobItemStatus::Skipped->value] ?? 0,
            failed: $byStatus[JobItemStatus::Failed->value] ?? 0,
            cancelled: $byStatus[JobItemStatus::Cancelled->value] ?? 0,
        );
    }

    public function total(): int
    {
        return $this->pending + $this->restored + $this->alreadyRestored + $this->conflict
            + $this->skipped + $this->failed + $this->cancelled;
    }
}
