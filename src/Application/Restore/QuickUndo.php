<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper), never printed as HTML.

use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Domain\Restore\JobStatus;
use SelectiveUndo\Infrastructure\Storage\JobRepository;

/**
 * Undo of a whole operation in one step. The plan is built and checked exactly as
 * for the preview; only the preview screen is skipped, and only when every item is
 * ready (or already restored) and the operation is small.
 */
final class QuickUndo
{
    public const MAX_OBJECTS = 20;
    public const MAX_FIELDS = 100;

    public function __construct(
        private readonly BuildRestorePlan $plans,
        private readonly PlanView $planView,
        private readonly CreateRestoreJob $createJob,
        private readonly ExecuteRestoreJob $executeJob,
        private readonly JobView $jobView,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * @return array{status: 'restored'|'needs_review'|'nothing_to_undo', plan_id: string|null, reason?: 'changed_again'|'blocked'|'too_large', job?: array<string, mixed>}
     *
     * @throws DomainError
     */
    public function handle(int $userId, string $changesetUuid, string $idempotencyKey): array
    {
        // A repeated click or a retried request: report the undo that already ran.
        $done = $this->createJob->findByKey($userId, $idempotencyKey);

        if ($done !== null) {
            return $this->restored($userId, $done);
        }

        /**
         * Filters the size up to which an operation is undone without a preview.
         *
         * @param array{objects: int, fields: int} $limits
         */
        $limits = (array) apply_filters('selective_undo/quick_undo_limits', ['objects' => self::MAX_OBJECTS, 'fields' => self::MAX_FIELDS]);
        $planUuid = $this->plans->handle($userId, ['type' => 'changeset', 'changeset_id' => $changesetUuid]);
        $plan = $this->planView->forUser($planUuid, $userId);

        if ($plan === null) {
            throw new DomainError('su_not_found', 404, __('This preview does not exist.', 'selective-undo'));
        }

        /** @var array<string, int> $s */
        $s = $plan['summary'];
        $blocking = $s['conflicts'] + $s['blocked'] + $s['missing'] + $s['forbidden'] + $s['unsupported'];

        if ($s['ready'] === 0 && $blocking === 0) {
            return ['status' => 'nothing_to_undo', 'plan_id' => $planUuid];
        }

        $reason = match (true) {
            $s['conflicts'] > 0 => 'changed_again',
            $blocking > 0 => 'blocked',
            $s['objects'] > (int) ($limits['objects'] ?? 0) || $s['fields'] > (int) ($limits['fields'] ?? 0) => 'too_large',
            default => null,
        };

        if ($reason !== null) {
            return ['status' => 'needs_review', 'plan_id' => $planUuid, 'reason' => $reason];
        }

        try {
            $started = $this->createJob->handle($userId, $planUuid, $idempotencyKey);
        } catch (DomainError $e) {
            // Two requests with the same key raced: the other one started the undo.
            $done = $e->errorCode === 'su_idempotency_key_reused' ? $this->createJob->findByKey($userId, $idempotencyKey) : null;

            if ($done === null) {
                throw $e;
            }

            return $this->restored($userId, $done);
        }

        $job = $this->jobs->findByUuid($started['job_uuid']);

        if ($job !== null && JobStatus::tryFrom((string) $job['status'])?->isTerminal() === false) {
            // Small by definition (limits above): finish within this request.
            $this->executeJob->run((int) $job['id'], 10.0);
        }

        return $this->restored($userId, $started['job_uuid'], $planUuid);
    }

    /**
     * @return array{status: 'restored', plan_id: string|null, job: array<string, mixed>}
     */
    private function restored(int $userId, string $jobUuid, ?string $planUuid = null): array
    {
        $job = $this->jobView->forUser($jobUuid, $userId) ?? [];

        return ['status' => 'restored', 'plan_id' => $planUuid ?? (isset($job['plan_id']) ? (string) $job['plan_id'] : null), 'job' => $job];
    }
}
