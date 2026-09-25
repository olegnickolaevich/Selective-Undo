<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

final readonly class JobLease
{
    public function __construct(
        public int $jobId,
        public string $token,
        public int $actorUserId,
        public int $restoreChangesetId,
        public int $blogId,
    ) {
    }
}
