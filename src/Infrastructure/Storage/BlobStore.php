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
     * Stores the values of one capture with as few statements as the size limit of a
     * query allows: one write instead of one per value. Each commit on the capture
     * path costs a disk flush, which dominates the time a save spends on history.
     *
     * @param list<FieldValue> $values
     *
     * @return list<int|InvalidPayload> Blob ID of each value, or why it was not stored.
     *
     * @throws StorageError
     */
    public function putMany(array $values): array
    {
        if (DbDialect::isSqlite()) {
            return array_map(function (FieldValue $value): int|InvalidPayload {
                try {
                    return $this->put($value)['id'];
                } catch (InvalidPayload $e) {
                    return $e;
                }
            }, $values);
        }

        // Values stored before (the "before" of an edit almost always is) are neither
        // compressed nor sent again: only their IDs are looked up.
        $hashes = array_map(static fn (FieldValue $value): string => $value->hash(), $values);
        $results = $hashes;
        $ids = $this->existing(array_values(array_unique($hashes)));
        $codec = $this->codec();
        $rows = [];

        foreach ($values as $i => $value) {
            if (isset($ids[$hashes[$i]]) || isset($rows[$hashes[$i]])) {
                continue;
            }

            try {
                $rows[$hashes[$i]] = $codec->pack($value);
            } catch (InvalidPayload $e) {
                $results[$i] = $e;
            }
        }

        // Escaping can double binary payloads in the SQL text.
        $budget = max(1 << 20, intdiv($this->dbInfo->maxAllowedPacket(), 3));
        $chunk = [];
        $bytes = 0;

        foreach ($rows as $row) {
            if ($chunk !== [] && $bytes + $row['stored_bytes'] > $budget) {
                $ids += $this->upsertChunk($chunk);
                [$chunk, $bytes] = [[], 0];
            }

            $chunk[] = $row;
            $bytes += $row['stored_bytes'];
        }

        if ($chunk !== []) {
            $ids += $this->upsertChunk($chunk);
        }

        $out = [];

        foreach ($results as $r) {
            $out[] = $r instanceof InvalidPayload ? $r : ($ids[$r] ?? throw new StorageError('blob_insert_failed'));
        }

        return $out;
    }

    /**
     * IDs of values already stored. Their last_seen_at is refreshed when it is more than
     * a minute old, so the collector (grace period of at least 5 minutes) never removes
     * a value a capture is about to reference.
     *
     * @param list<string> $hashes
     *
     * @return array<string, int> content hash => blob ID
     */
    private function existing(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $rows = $this->db->get_results($this->db->prepare(
            "SELECT id, content_hash, last_seen_at < " . SqlTime::utc(-MINUTE_IN_SECONDS) . " AS stale FROM {$this->t->blobs}
             WHERE content_hash IN (" . implode(', ', array_fill(0, count($hashes), '%s')) . ')',
            ...$hashes
        ), ARRAY_A);

        if (!is_array($rows)) {
            throw new StorageError('blob_read_failed');
        }

        $ids = [];
        $stale = [];

        foreach ($rows as $row) {
            $ids[(string) $row['content_hash']] = (int) $row['id'];

            if ((int) $row['stale'] === 1) {
                $stale[] = (int) $row['id'];
            }
        }

        if ($stale !== [] && $this->db->query("UPDATE {$this->t->blobs} SET last_seen_at = " . SqlTime::utc() . ' WHERE id IN (' . implode(',', $stale) . ')') === false) {
            throw new StorageError('blob_insert_failed');
        }

        return $ids;
    }
    /**
     * @param non-empty-list<array{content_hash: string, encoding: string, payload: string, uncompressed_bytes: int, stored_bytes: int}> $rows
     *
     * @return array<string, int> content hash => blob ID
     */
    private function upsertChunk(array $rows): array
    {
        $now = SqlTime::utc();
        $values = array_map(fn (array $r): string => (string) $this->db->prepare(
            "(%s, %s, %s, %d, %d, {$now}, {$now})",
            $r['content_hash'],
            $r['encoding'],
            $r['payload'],
            $r['uncompressed_bytes'],
            $r['stored_bytes']
        ), $rows);

        // Tables with binary columns bypass wpdb's charset stripping, so raw bytes are safe here.
        $ok = $this->db->query(
            "INSERT INTO {$this->t->blobs} (content_hash, encoding, payload, uncompressed_bytes, stored_bytes, created_at, last_seen_at)
             VALUES " . implode(', ', $values) . "
             ON DUPLICATE KEY UPDATE last_seen_at = {$now}"
        );
        $found = $ok === false ? null : $this->db->get_results($this->db->prepare(
            "SELECT id, content_hash FROM {$this->t->blobs} WHERE content_hash IN (" . implode(', ', array_fill(0, count($rows), '%s')) . ')',
            ...array_column($rows, 'content_hash')
        ), ARRAY_A);

        if (!is_array($found) || count($found) !== count($rows)) {
            throw new StorageError('blob_insert_failed');
        }

        $ids = [];

        foreach ($found as $row) {
            $ids[(string) $row['content_hash']] = (int) $row['id'];
        }

        return $ids;
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
