<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Change\ChangeEvent;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Journal writes inside restore transactions (invariant I-4: every content write
 * made by the plugin is journaled in the same transaction).
 */
final class RestoreJournal
{
    public function __construct(
        private readonly Tables $t,
        private readonly BlobStore $blobs,
    ) {
    }

    public function createRestoreChangeset(RestoreTransaction $tx, int $jobId, int $actorUserId, string $requestUuid): int
    {
        $tx->write(
            "INSERT INTO `{$this->t->changesets}`
             (uuid, request_uuid, restore_job_id, actor_user_id, kind, source, grouping_mode, label, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'restore', 'restore', 'request', '', 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            'ssii',
            [wp_generate_uuid4(), $requestUuid, $jobId, $actorUserId]
        );

        return $tx->lastInsertId();
    }

    /**
     * before = value found at restore time, after = restored target.
     * sequence_no = plan item ID: unique within the job changeset across batches and retries.
     */
    public function appendRestoreChange(
        RestoreTransaction $tx,
        int $changesetId,
        int $sequenceNo,
        ObjectRef $object,
        AdapterInterface $adapter,
        string $fieldKey,
        FieldValue $before,
        int $afterBlobId,
        string $afterHash,
        int $revertsChangeId,
    ): int {
        $beforeBlobId = $this->blobs->putTx($tx, $before);
        $tx->write(
            "INSERT INTO `{$this->t->changes}`
             (changeset_id, sequence_no, event, object_type, object_subtype, object_id, adapter_key, adapter_version, field_key,
              before_blob_id, after_blob_id, before_hash, after_hash, quality, restorable, reason_code, reverts_change_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'verified', 1, NULL, ?, UTC_TIMESTAMP())",
            'iisssisisiissi',
            [
                $changesetId, $sequenceNo, ChangeEvent::Restore->value, $object->type, $object->subtype, $object->id,
                $adapter->key(), $adapter->version(), $fieldKey, $beforeBlobId, $afterBlobId, $before->hash(), $afterHash,
                $revertsChangeId,
            ]
        );

        return $tx->lastInsertId();
    }
}
