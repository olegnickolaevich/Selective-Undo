<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Application\Capture\RequestContext;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Infrastructure\Database\DbError;
use SelectiveUndo\Infrastructure\Database\RestoreConnection;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Storage\JobRepository;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;
use SelectiveUndo\Infrastructure\Storage\RestoreJournal;

/**
 * Starts a restore for a plan exactly once.
 *
 * Idempotency: the same (user, key) always returns the same job; a plan can be
 * claimed by one job only (unique constraints protect against races between
 * processes). Small jobs run inline in the same request.
 */
final class CreateRestoreJob
{
    /** Objects above this count are processed by the queue (PRO bulk restore). */
    public const INLINE_OBJECT_LIMIT = 5;

    public function __construct(
        private readonly RestoreConnection $db,
        private readonly Tables $t,
        private readonly PlanRepository $plans,
        private readonly JobRepository $jobs,
        private readonly ExecuteRestoreJob $executor,
        private readonly RestoreReadiness $readiness,
        private readonly RequestContext $request,
        private readonly RestoreJournal $journal,
    ) {
    }

    /**
     * @return array{job_uuid: string, created: bool}
     *
     * @throws DomainError
     */
    public function handle(int $userId, string $planUuid, string $idempotencyKey): array
    {
        $plan = $this->plans->findByUuid($planUuid);

        if ($plan === null || (int) $plan['actor_user_id'] !== $userId || (int) $plan['blog_id'] !== get_current_blog_id()) {
            throw new DomainError('su_not_found', 404, __('This preview does not exist.', 'selective-undo'));
        }

        $keyHash = hash('sha256', strtolower($idempotencyKey), true);
        $existing = $this->jobs->findByIdempotency($userId, $keyHash);

        if ($existing !== null) {
            return $this->replay($existing, (int) $plan['id']);
        }

        $blockers = $this->readiness->blockers();

        if ($blockers !== []) {
            throw new DomainError(
                'su_restore_unavailable',
                503,
                __('Restoring is unavailable on this site right now. See Settings → Diagnostics.', 'selective-undo'),
                ['blockers' => $blockers]
            );
        }

        $this->assertStartable($plan);

        try {
            $jobId = $this->db->transactional(function (RestoreTransaction $tx) use ($plan, $userId, $keyHash): int {
                $claimed = $tx->write(
                    "UPDATE `{$this->t->plans}` SET status = 'consumed'
                     WHERE id = ? AND status = 'ready' AND expires_at > UTC_TIMESTAMP()",
                    'i',
                    [(int) $plan['id']]
                );

                if ($claimed !== 1) {
                    throw new PlanNotClaimable();
                }

                $objects = (int) $plan['object_count'];
                $tx->write(
                    "INSERT INTO `{$this->t->jobs}`
                     (uuid, plan_id, blog_id, actor_user_id, idempotency_key_hash, mode, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'queued', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    'siiiss',
                    [wp_generate_uuid4(), (int) $plan['id'], get_current_blog_id(), $userId, $keyHash, $objects > self::INLINE_OBJECT_LIMIT ? 'queued' : 'inline']
                );
                $jobId = $tx->lastInsertId();
                $changesetId = $this->journal->createRestoreChangeset($tx, $jobId, $userId, $this->request->requestUuid());
                $items = $tx->write(
                    "INSERT INTO `{$this->t->jobItems}` (job_id, plan_item_id, object_type, object_id, status)
                     SELECT ?, id, object_type, object_id, 'pending' FROM `{$this->t->planItems}`
                     WHERE plan_id = ? AND status = 'ready' ORDER BY id",
                    'ii',
                    [$jobId, (int) $plan['id']]
                );
                $tx->write(
                    "UPDATE `{$this->t->jobs}` SET restore_changeset_id = ?, total_items = ? WHERE id = ?",
                    'iii',
                    [$changesetId, $items, $jobId]
                );
                $tx->write(
                    "UPDATE `{$this->t->plans}` SET claimed_job_id = ? WHERE id = ?",
                    'ii',
                    [$jobId, (int) $plan['id']]
                );

                return $jobId;
            });
        } catch (PlanNotClaimable) {
            // Lost the claim to a parallel request. If it used the same key, this request
            // is a retry of that one and must get the same job.
            $existing = $this->jobs->findByIdempotency($userId, $keyHash);

            if ($existing !== null) {
                return $this->replay($existing, (int) $plan['id']);
            }

            $this->assertStartable($this->plans->findById((int) $plan['id']) ?? $plan, true);

            throw new DomainError('su_plan_expired', 409, __('This preview has expired. Check the changes again.', 'selective-undo'));
        } catch (DbError $e) {
            if (!$e->isDuplicateKey()) {
                throw $e;
            }

            // A parallel request with the same key (or for the same plan) won the race.
            $existing = $this->jobs->findByIdempotency($userId, $keyHash);

            if ($existing !== null) {
                return $this->replay($existing, (int) $plan['id']);
            }

            throw new DomainError('su_plan_consumed', 409, __('This preview has already been used to start a restore.', 'selective-undo'));
        }

        $job = $this->jobs->findById($jobId);

        /**
         * Fires after a restore job was created, before it runs.
         *
         * @param array<string, mixed> $job Job row. No content.
         */
        do_action('selective_undo/restore_job_started', $job);

        if (is_array($job) && $job['mode'] === 'inline') {
            $this->executor->run($jobId);
        } else {
            wp_schedule_single_event(time(), ExecuteRestoreJob::CRON_HOOK, [$jobId]);
        }

        return ['job_uuid' => (string) ($job['uuid'] ?? ''), 'created' => true];
    }

    /**
     * @param array<string, mixed> $existing
     *
     * @return array{job_uuid: string, created: bool}
     */
    private function replay(array $existing, int $planId): array
    {
        if ((int) $existing['plan_id'] !== $planId) {
            throw new DomainError('su_idempotency_key_reused', 422, __('This request key was already used for another restore.', 'selective-undo'));
        }

        return ['job_uuid' => (string) $existing['uuid'], 'created' => false];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function assertStartable(array $plan, bool $afterRace = false): void
    {
        if ($plan['status'] === 'consumed') {
            $job = $plan['claimed_job_id'] !== null ? $this->jobs->findById((int) $plan['claimed_job_id']) : null;

            throw new DomainError(
                'su_plan_consumed',
                409,
                __('This preview has already been used to start a restore.', 'selective-undo'),
                ['job_id' => $job['uuid'] ?? null]
            );
        }

        if ($plan['status'] !== 'ready' || strtotime((string) $plan['expires_at'] . ' UTC') <= time()) {
            $this->plans->expire((int) $plan['id']);

            throw new DomainError('su_plan_expired', 409, __('This preview has expired. Check the changes again.', 'selective-undo'));
        }

        if (!$afterRace && (int) $plan['ready_count'] === 0) {
            throw new DomainError('su_nothing_to_restore', 409, __('Nothing in this preview can be restored.', 'selective-undo'));
        }
    }
}
