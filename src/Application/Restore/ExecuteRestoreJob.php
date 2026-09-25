<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Restore\JobOutcome;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;
use SelectiveUndo\Infrastructure\Storage\JobRepository;
use SelectiveUndo\Infrastructure\Storage\JournalRepository;

/**
 * Processes a job object by object within a time budget. One object is one
 * transaction; a mass restore is never one global transaction.
 */
final class ExecuteRestoreJob
{
    public const CRON_HOOK = 'sundo_run_job';

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly RestoreObjectExecutor $executor,
        private readonly JournalRepository $journal,
        private readonly JobOutcome $outcome,
        private readonly EventLog $log,
    ) {
    }

    /**
     * @return bool True when the job reached a terminal status in this call.
     */
    public function run(int $jobId, float $budgetSeconds = 20.0): bool
    {
        $lease = $this->jobs->claim($jobId);

        if ($lease === null) {
            return false;
        }

        if ($lease->blogId !== get_current_blog_id()) {
            // Never process an object of another site after a stray switch_to_blog().
            $this->log->log('error', 'job_blog_mismatch', [], $jobId);
            $this->jobs->release($lease);

            return false;
        }

        $started = microtime(true);

        try {
            while (true) {
                if ($this->jobs->isCancelRequested($jobId)) {
                    $this->jobs->cancelPending($jobId);
                    break;
                }

                $next = $this->jobs->nextPendingObject($jobId);

                if ($next === null) {
                    break;
                }

                try {
                    $this->executor->restoreObject($lease, $next['object'], $next['adapter_key']);
                } catch (LeaseLost) {
                    return false;
                } catch (\Throwable $e) {
                    $this->log->exception('restore_object_failed', $e, $next['object']->id, $jobId);
                    $final = $this->jobs->recordFailure($jobId, $next['object'], $this->codeOf($e));

                    if (!$final) {
                        break; // retried later by the queue with a fresh lease
                    }
                }

                if (microtime(true) - $started > $budgetSeconds) {
                    break;
                }
            }

            return $this->finishIfDone($lease);
        } finally {
            $this->jobs->release($lease);
        }
    }

    private function finishIfDone(JobLease $lease): bool
    {
        $counters = $this->jobs->counters($lease->jobId);
        $cancelRequested = $this->jobs->isCancelRequested($lease->jobId);
        $status = $this->outcome->resolve($counters, $cancelRequested);

        if ($status === null) {
            if (!wp_next_scheduled(self::CRON_HOOK, [$lease->jobId])) {
                wp_schedule_single_event(time() + 5, self::CRON_HOOK, [$lease->jobId]);
            }

            return false;
        }

        if (!$this->jobs->complete($lease, $status, $counters)) {
            return false;
        }

        $this->journal->seal($lease->restoreChangesetId);
        $this->jobs->setPostprocessStatus($lease->jobId);
        $job = $this->jobs->findById($lease->jobId);

        /**
         * Fires once when a restore job reaches a terminal status.
         *
         * @param array<string, mixed> $job Job row (IDs, status, counters). No content.
         */
        do_action('selective_undo/restore_job_finished', $job);

        return true;
    }

    private function codeOf(\Throwable $e): string
    {
        foreach (['reasonCode', 'errorCode'] as $property) {
            if (property_exists($e, $property) && is_string($e->{$property})) {
                return substr($e->{$property}, 0, 64);
            }
        }

        return substr(strtolower((new \ReflectionClass($e))->getShortName()), 0, 64);
    }
}
