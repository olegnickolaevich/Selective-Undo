<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Database\DbInfo;
use SelectiveUndo\Infrastructure\Database\StorageError;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Settings\Settings;

/**
 * Content-addressed, deduplicated value storage.
 *
 * last_seen_at is bumped on every reuse; garbage collection deletes only rows
 * that are unreferenced AND not seen for a grace period, which makes it safe
 * against concurrent captures without reference counters.
 */
final class BlobStore
{
    private const UPSERT = 'INSERT INTO %1$s (content_hash, encoding, payload, uncompressed_bytes, stored_bytes, created_at, last_seen_at)
        VALUES (%2$s, %3$s, %4$s, %5$s, %6$s, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE last_seen_at = UTC_TIMESTAMP(), id = LAST_INSERT_ID(id)';

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly Settings $settings,
        private readonly DbInfo $dbInfo,
    ) {
    }

    /** The size limit is read on every call: settings may change during a request. */
    public function codec(): BlobCodec
    {
        return new BlobCodec($this->settings->maxValueBytes($this->dbInfo->maxAllowedPacket()));
    }

    /**
     * Stores a value through $wpdb (capture path).
     *
     * @return array{id: int, hash: string}
     *
     * @throws InvalidPayload payload_too_large
     * @throws StorageError
     */
    public function put(FieldValue $value): array
    {
        $row = $this->codec()->pack($value);
        // Tables with binary columns bypass wpdb's charset stripping, so raw bytes are safe here.
        $sql = $this->db->prepare(
            sprintf(self::UPSERT, $this->t->blobs, '%s', '%s', '%s', '%d', '%d'),
            $row['content_hash'],
            $row['encoding'],
            $row['payload'],
            $row['uncompressed_bytes'],
            $row['stored_bytes']
        );

        if ($this->db->query($sql) === false || (int) $this->db->insert_id <= 0) {
            throw new StorageError('blob_insert_failed');
        }

        return ['id' => (int) $this->db->insert_id, 'hash' => $row['content_hash']];
    }

    /**
     * Stores a value inside a restore transaction.
     *
     * @throws InvalidPayload
     */
    public function putTx(RestoreTransaction $tx, FieldValue $value): int
    {
        $row = $this->codec()->pack($value);
        $tx->write(
            sprintf(self::UPSERT, $this->t->blobs, '?', '?', '?', '?', '?'),
            'sssii',
            [$row['content_hash'], $row['encoding'], $row['payload'], $row['uncompressed_bytes'], $row['stored_bytes']]
        );

        return $tx->lastInsertId();
    }

    /**
     * @throws InvalidPayload
     */
    public function loadVerified(int $id, string $expectedHash): FieldValue
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT content_hash, encoding, payload, uncompressed_bytes FROM {$this->t->blobs} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            throw new InvalidPayload('payload_missing');
        }

        if (!hash_equals($expectedHash, (string) $row['content_hash'])) {
            throw new InvalidPayload('payload_hash_mismatch');
        }

        return $this->codec()->unpack(
            (string) $row['encoding'],
            (string) $row['payload'],
            (int) $row['uncompressed_bytes'],
            $expectedHash
        );
    }
}
