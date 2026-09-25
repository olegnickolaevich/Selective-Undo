<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Restore\Comparison;
use SelectiveUndo\Domain\Restore\ConflictDetector;
use SelectiveUndo\Domain\Restore\JobItemStatus;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Database\RestoreConnection;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JobRepository;
use SelectiveUndo\Infrastructure\Storage\Outbox;
use SelectiveUndo\Infrastructure\Storage\RestoreJournal;

/**
 * Restores one object.
 *
 * Phase 1 (no transaction): permissions of the job author, loading and verifying
 *   targets, checks that call WordPress APIs or third-party filters (kses,
 *   preconditions). Third-party code must NOT run while FOR UPDATE locks are held:
 *   if it wrote the same row through $wpdb the PHP process would block itself.
 * Phase 2 (one short transaction on the dedicated connection): fencing, locking job
 *   items, locking the object row, fresh three-way comparison, writing only ready
 *   fields, journal, item statuses and counters, outbox. All or nothing.
 * Phase 3 (after commit): immediate attempt to deliver outbox events.
 */
final class RestoreObjectExecutor
{
    public function __construct(
        private readonly RestoreConnection $db,
        private readonly AdapterRegistry $adapters,
        private readonly BlobStore $blobs,
        private readonly JobRepository $jobs,
        private readonly RestoreJournal $journal,
        private readonly Outbox $outbox,
        private readonly ConflictDetector $detector,
        private readonly RestorePreconditions $preconditions,
    ) {
    }

    public function restoreObject(JobLease $lease, ObjectRef $object, string $adapterKey): ObjectRestoreResult
    {
        // ---------- Phase 1 ----------
        $adapter = $this->adapters->find($adapterKey);
        $pending = $this->jobs->pendingFor($lease->jobId, $object);

        if ($pending === []) {
            return ObjectRestoreResult::nothingToDo($object);
        }

        $objectVerdict = match (true) {
            $adapter === null => 'adapter_unavailable',
            // Permissions of the job author: in the background the current user is 0.
            !$adapter->authorize($lease->actorUserId, $object, 'restore') => 'not_allowed',
            default => null,
        };

        /** @var array<int, FieldValue> $targets plan item id => target */
        $targets = [];
        /** @var array<int, string> $preBlocked plan item id => reason */
        $preBlocked = [];

        if ($adapter !== null && $objectVerdict === null) {
            foreach ($pending as $item) {
                $planItemId = (int) $item['plan_item_id'];

                if ((int) $item['adapter_version'] !== $adapter->version()) {
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
        }

        // ---------- Phase 2 ----------
        $result = $this->db->transactional(
            function (RestoreTransaction $tx) use ($lease, $object, $adapter, $objectVerdict, $targets, $preBlocked): ObjectRestoreResult {
                $this->jobs->fence($tx, $lease);
                $locked = $this->jobs->lockPendingFor($tx, $lease->jobId, $object);

                if ($locked === []) {
                    return ObjectRestoreResult::nothingToDo($object);
                }

                $outcomes = [];
                $current = null;

                if ($adapter === null || $objectVerdict !== null) {
                    foreach ($locked as $item) {
                        $outcomes[] = new ItemOutcome($item, JobItemStatus::Skipped, $objectVerdict ?? 'adapter_unavailable');
                    }
                } else {
                    $current = $adapter->lockAndRead($tx, $object);
                }

                if ($adapter !== null && $objectVerdict === null && $current === null) {
                    foreach ($locked as $item) {
                        $outcomes[] = new ItemOutcome($item, JobItemStatus::Skipped, 'object_missing');
                    }
                }

                $writes = [];

                if ($adapter !== null && $current !== null) {
                    foreach ($locked as $item) {
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
                    }
                }

                foreach ($outcomes as $outcome) {
                    $resultChangeId = null;

                    if ($outcome->status === JobItemStatus::Restored && $adapter !== null && $outcome->currentValue !== null) {
                        $field = (string) $outcome->item['field_key'];
                        $resultChangeId = $this->journal->appendRestoreChange(
                            $tx,
                            changesetId: $lease->restoreChangesetId,
                            sequenceNo: (int) $outcome->item['plan_item_id'],
                            object: new ObjectRef($object->type, $object->id, $current?->objectSubtype() ?? $object->subtype),
                            adapter: $adapter,
                            fieldKey: $field,
                            before: $outcome->currentValue,
                            afterBlobId: (int) $outcome->item['target_blob_id'],
                            afterHash: (string) $outcome->item['target_hash'],
                            revertsChangeId: (int) $outcome->item['last_change_id'],
                        );
                    }

                    $this->jobs->finishItem($tx, (int) $outcome->item['id'], $outcome->status, $outcome->reasonCode, $resultChangeId);
                }

                $this->jobs->bumpCounters($tx, $lease->jobId, $outcomes);

                if ($writes !== [] && $adapter !== null) {
                    $this->outbox->enqueue($tx, 'object_restored', $lease->jobId, $object, [
                        'job_id' => $lease->jobId,
                        'object_type' => $object->type,
                        'object_id' => $object->id,
                        'adapter_key' => $adapter->key(),
                        'fields' => array_keys($writes),
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
}
