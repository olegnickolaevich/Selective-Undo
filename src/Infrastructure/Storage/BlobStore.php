<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Database\DbDialect;
use SelectiveUndo\Infrastructure\Database\DbInfo;
use SelectiveUndo\Infrastructure\Database\SqlTime;
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
        VALUES (%2$s, %3$s, %4$s, %5$s, %6$s, %7$s, %7$s)
        ON DUPLICATE KEY UPDATE last_seen_at = %7$s, id = LAST_INSERT_ID(id)';

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

        if (DbDialect::isSqlite()) {
            $id = $this->putWithoutUpsert(
                fn (string $sql, array $params): ?string => $this->db->get_var($this->db->prepare($sql, ...$params)),
                fn (string $sql, array $params): bool => $this->db->query($this->db->prepare($sql, ...$params)) !== false,
                fn (): int => (int) $this->db->insert_id,
                '%s',
                $row
            );

            if ($id <= 0) {
                throw new StorageError('blob_insert_failed');
            }

            return ['id' => $id, 'hash' => $row['content_hash']];
        }

        // Tables with binary columns bypass wpdb's charset stripping, so raw bytes are safe here.
        $sql = $this->db->prepare(
            sprintf(self::UPSERT, $this->t->blobs, '%s', '%s', '%s', '%d', '%d', SqlTime::utc()),
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

        if (DbDialect::isSqlite()) {
            // Restore transactions on SQLite hold the write lock: no concurrent insert.
            return $this->putWithoutUpsert(
                static function (string $sql, array $params) use ($tx): ?string {
                    $rows = $tx->select($sql, str_repeat('s', count($params)), $params);

                    return isset($rows[0]['id']) ? (string) $rows[0]['id'] : null;
                },
                static fn (string $sql, array $params): bool => $tx->write($sql, str_repeat('s', count($params)), $params) >= 0,
                static fn (): int => $tx->lastInsertId(),
                '?',
                $row
            );
        }

        $tx->write(
            sprintf(self::UPSERT, $this->t->blobs, '?', '?', '?', '?', '?', SqlTime::utc()),
            'sssii',
            [$row['content_hash'], $row['encoding'], $row['payload'], $row['uncompressed_bytes'], $row['stored_bytes']]
        );

        return $tx->lastInsertId();
    }

    /**
     * Find-or-insert for databases without INSERT ... ON DUPLICATE KEY UPDATE with
     * LAST_INSERT_ID(expr) (SQLite). A concurrent insert of the same content loses on
     * the unique key and then finds the winner's row.
     *
     * @param callable(string, list<string>): ?string $var
     * @param callable(string, list<string>): bool $query
     * @param callable(): int $insertId
     * @param array{content_hash: string, encoding: string, payload: string, uncompressed_bytes: int, stored_bytes: int} $row
     */
    private function putWithoutUpsert(callable $var, callable $query, callable $insertId, string $ph, array $row): int
    {
        $find = "SELECT id FROM {$this->t->blobs} WHERE " . DbDialect::binaryEquals('content_hash', $ph);
        $hash = DbDialect::binaryParam($row['content_hash']);
        $now = trim(SqlTime::utc(), "'");

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $id = (int) $var($find, [$hash]);

            if ($id > 0) {
                $query("UPDATE {$this->t->blobs} SET last_seen_at = {$ph} WHERE id = {$ph}", [$now, (string) $id]);

                return $id;
            }

            $inserted = $query(
                "INSERT INTO {$this->t->blobs} (content_hash, encoding, payload, uncompressed_bytes, stored_bytes, created_at, last_seen_at)
                 VALUES ({$ph}, {$ph}, {$ph}, {$ph}, {$ph}, {$ph}, {$ph})",
                [$row['content_hash'], $row['encoding'], $row['payload'], (string) $row['uncompressed_bytes'], (string) $row['stored_bytes'], $now, $now]
            );

            if ($inserted) {
                return $insertId();
            }
        }

        return 0;
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
