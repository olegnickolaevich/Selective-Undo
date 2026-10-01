<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Contracts\TransactionRunner;
use SelectiveUndo\Domain\Restore\Comparison;
use SelectiveUndo\Domain\Restore\ConflictDetector;
use SelectiveUndo\Domain\Restore\JobItemStatus;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Database\DbError;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JobRepository;
use SelectiveUndo\Infrastructure\Storage\Outbox;
use SelectiveUndo\Infrastructure\Storage\RestoreJournal;
use SelectiveUndo\Infrastructure\WordPress\ObjectLock;

/**
 * Restores one object.
 *
 * Phase 1 (no transaction): permissions of the job author, loading and verifying
 *   targets, checks that call WordPress APIs or third-party filters (kses,
 *   preconditions). Third-party code must NOT run while FOR UPDATE locks are held:
 *   if it wrote the same row through $wpdb the PHP process would block itself.
 * Phase 2 (one short transaction on the dedicated connection, or on $wpdb in
 *   compatibility mode) for all adapters of the object: fencing, locking job
 *   items, locking the object row, then per adapter (by priority) a fresh three-way
 *   comparison, writing only ready fields, journal and item statuses; counters and
 *   outbox. All or nothing.
 * Phase 3 (after commit): immediate attempt to deliver outbox events.
 */
final class RestoreObjectExecutor
{
    public function __construct(
        private readonly TransactionRunner $db,
        private readonly AdapterRegistry $adapters,
        private readonly BlobStore $blobs,
        private readonly JobRepository $jobs,
        private readonly RestoreJournal $journal,
        private readonly Outbox $outbox,
        private readonly ConflictDetector $detector,
        private readonly RestorePreconditions $preconditions,
        private readonly ObjectLock $objectLock,
    ) {
    }

    public function restoreObject(JobLease $lease, ObjectRef $object): ObjectRestoreResult
    {
        // ---------- Phase 1 ----------
        $pending = $this->jobs->pendingFor($lease->jobId, $object);

        if ($pending === []) {
            return ObjectRestoreResult::nothingToDo($object);
        }

        /** @var array<string, string|null> $verdicts adapter key => reason the whole adapter is skipped */
        $verdicts = [];
        /** @var array<int, FieldValue> $targets plan item id => target */
        $targets = [];
        /** @var array<int, string> $preBlocked plan item id => reason */
        $preBlocked = [];

        foreach ($pending as $item) {
            $key = (string) $item['adapter_key'];
            $adapter = $this->adapters->find($key);
            $verdicts[$key] ??= match (true) {
                $adapter === null => 'adapter_unavailable',
                // Permissions of the job author: in the background the current user is 0.
                !$adapter->authorize($lease->actorUserId, $object, 'restore') => 'not_allowed',
                default => null,
            };

            if ($adapter === null || $verdicts[$key] !== null) {
                continue;
            }

            $planItemId = (int) $item['plan_item_id'];

            if (!$adapter->acceptsVersion((int) $item['adapter_version'])) {
                $preBlocked[$planItemId] = 'adapter_version_mismatch';
                continue;
            }

            try {
                // Blobs are immutable (content addressed): reading outside the transaction is safe.
                $target = $this->blobs->loadVerified((int) $item['target_blob_id'], (string) $item['target_hash']);
            } catch (InvalidPayload $e) {
                $preBlocked[$planItemId] = $e->reasonCode;
                continue;
            }

            $field = (string) $item['field_key'];
            $reason = $adapter->validateTarget($lease->actorUserId, $object, $field, $target)
                ?? $this->preconditions->check($object, $field, $lease->actorUserId);

            if ($reason !== null) {
                $preBlocked[$planItemId] = $reason;
                continue;
            }

            $targets[$planItemId] = $target;
        }

        // ---------- Phase 2 ----------
        $result = $this->db->transactional(
            function (RestoreTransaction $tx) use ($lease, $object, $verdicts, $targets, $preBlocked): ObjectRestoreResult {
                $this->jobs->fence($tx, $lease);
                $locked = $this->jobs->lockPendingFor($tx, $lease->jobId, $object);

                if ($locked === []) {
                    return ObjectRestoreResult::nothingToDo($object);
                }

                // Lock order (one per object, then by adapter priority) prevents deadlocks
                // between restores of the same object touching different adapters.
                $this->objectLock->lock($tx, $object);
                $byAdapter = [];

                foreach ($locked as $item) {
                    $byAdapter[(string) $item['adapter_key']][] = $item;
                }

                uksort($byAdapter, fn (string $a, string $b): int => [$this->adapters->priority($a), $a] <=> [$this->adapters->priority($b), $b]);
                $outcomes = [];
                $written = [];
                $subtype = $object->subtype;

                foreach ($byAdapter as $key => $items) {
                    $adapter = $this->adapters->find($key);
                    $verdict = $verdicts[$key] ?? ($adapter === null ? 'adapter_unavailable' : null);

                    if ($adapter === null || $verdict !== null) {
                        foreach ($items as $item) {
                            $outcomes[] = new ItemOutcome($item, JobItemStatus::Skipped, $verdict ?? 'adapter_unavailable');
                            $this->jobs->finishItem($tx, (int) $item['id'], JobItemStatus::Skipped, $verdict ?? 'adapter_unavailable', null);
                        }

                        continue;
                    }

                    [$adapterOutcomes, $fields, $current] = $this->restoreWithAdapter($tx, $lease, $object, $adapter, $items, $targets, $preBlocked);
                    $outcomes = [...$outcomes, ...$adapterOutcomes];
                    $subtype = $current?->objectSubtype() ?? $subtype;

                    if ($fields !== []) {
                        $written[$key] = $fields;
                    }
                }

                $this->jobs->bumpCounters($tx, $lease->jobId, $outcomes);

                if ($written !== []) {
                    $this->outbox->enqueue($tx, 'object_restored', $lease->jobId, $object, [
                        'job_id' => $lease->jobId,
                        'object_type' => $object->type,
                        'object_id' => $object->id,
                        'object_subtype' => $subtype,
                        'adapters' => $written,
                        'fields' => array_merge(...array_values($written)),
                        'actor_user_id' => $lease->actorUserId,
                    ]);
                }

                return ObjectRestoreResult::fromOutcomes($object, $outcomes);
            }
        );

        // ---------- Phase 3 ----------
        if ($result->restoredFields !== []) {
            // A failure here does not undo the restore: the event stays in the outbox.
            $this->outbox->dispatchPendingFor($lease->jobId, $object);
        }

        return $result;
    }

    /**
     * The items of one adapter, inside the object's transaction: fresh three-way
     * comparison, write of the ready fields, journal and item statuses.
     *
     * @param list<array<string, int|float|string|null>> $items
     * @param array<int, FieldValue> $targets
     * @param array<int, string> $preBlocked
     *
     * @return array{0: list<ItemOutcome>, 1: list<string>, 2: ObjectSnapshot|null} outcomes, written fields, snapshot
     */
    private function restoreWithAdapter(
        RestoreTransaction $tx,
        JobLease $lease,
        ObjectRef $object,
        AdapterInterface $adapter,
        array $items,
        array $targets,
        array $preBlocked,
    ): array {
        $outcomes = [];
        $current = $adapter->lockAndRead($tx, $object);

        if ($current === null) {
            foreach ($items as $item) {
                $outcomes[] = new ItemOutcome($item, JobItemStatus::Skipped, 'object_missing');
            }
        }

        $writes = [];

        if ($current !== null) {
            foreach ($items as $item) {
                $planItemId = (int) $item['plan_item_id'];
                $field = (string) $item['field_key'];
                $currentValue = $current->field($field);
                $comparison = $this->detector->compare(
                    $currentValue->hash(),
                    (string) $item['expected_hash'],
                    (string) $item['target_hash']
                );

                if ($comparison === Comparison::AlreadyRestored) {
                    $outcomes[] = new ItemOutcome($item, JobItemStatus::AlreadyRestored);
                    continue;
                }

                if ($comparison === Comparison::Conflict) {
                    $outcomes[] = new ItemOutcome($item, JobItemStatus::Conflict, 'current_value_changed');
                    continue;
                }

                $reason = $preBlocked[$planItemId] ?? $adapter->validateState($current, $field);

                if ($reason !== null || !isset($targets[$planItemId])) {
                    $outcomes[] = new ItemOutcome($item, JobItemStatus::Skipped, $reason ?? 'target_unavailable');
                    continue;
                }

                $writes[$field] = $targets[$planItemId];
                $outcomes[] = new ItemOutcome($item, JobItemStatus::Restored, null, $currentValue);
            }

            if ($writes !== []) {
                $adapter->write($tx, $current, $writes);

                if ($this->db->mode() === TransactionRunner::COMPAT) {
                    // No dedicated connection: make sure the write really landed before COMMIT.
                    $check = $adapter->lockAndRead($tx, $object);

                    foreach ($writes as $field => $value) {
                        if ($check === null || !$check->field($field)->equals($value)) {
                            throw new DbError('compat_verify_failed');
                        }
                    }
                }
            }
        }

        foreach ($outcomes as $outcome) {
            $resultChangeId = null;

            if ($outcome->status === JobItemStatus::Restored && $outcome->currentValue !== null) {
                $resultChangeId = $this->journal->appendRestoreChange(
                    $tx,
                    changesetId: $lease->restoreChangesetId,
                    sequenceNo: (int) $outcome->item['plan_item_id'],
                    object: new ObjectRef($object->type, $object->id, $current?->objectSubtype() ?? $object->subtype),
                    adapter: $adapter,
                    fieldKey: (string) $outcome->item['field_key'],
                    before: $outcome->currentValue,
                    afterBlobId: (int) $outcome->item['target_blob_id'],
                    afterHash: (string) $outcome->item['target_hash'],
                    revertsChangeId: (int) $outcome->item['last_change_id'],
                );
            }

            $this->jobs->finishItem($tx, (int) $outcome->item['id'], $outcome->status, $outcome->reasonCode, $resultChangeId);
        }

        return [$outcomes, array_keys($writes), $current];
    }
}
