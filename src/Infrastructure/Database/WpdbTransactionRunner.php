<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are codes, returned as REST API JSON errors or logged, never printed as HTML.

use SelectiveUndo\Domain\Contracts\TransactionRunner;

/**
 * Compatibility mode: restore transactions run on the shared $wpdb connection.
 *
 * Used on SQLite (WordPress Playground), where a second connection is impossible,
 * and on MySQL hosts that forbid it (SELECTIVE_UNDO_COMPAT_MODE). Weaker than the
 * dedicated connection: wpdb may reconnect and re-run a statement outside the
 * transaction. The executor therefore reads written fields back before COMMIT.
 */
final class WpdbTransactionRunner implements TransactionRunner
{
    private bool $inTransaction = false;

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function mode(): string
    {
        return self::COMPAT;
    }

    public function ping(): void
    {
        if ($this->db->get_var('SELECT 1') === null) {
            throw new StorageUnavailable('db_unavailable');
        }
    }

    public function transactional(callable $fn, int $maxAttempts = 3): mixed
    {
        if ($this->inTransaction) {
            throw new \LogicException('Nested restore transactions are not allowed.');
        }

        for ($attempt = 1; ; $attempt++) {
            // The SQLite driver turns this into BEGIN IMMEDIATE: the write lock is taken up
            // front, so the comparison and the write are atomic.
            $this->raw('START TRANSACTION');
            $this->inTransaction = true;

            try {
                $result = $fn($this);
            } catch (\Throwable $e) {
                $this->db->query('ROLLBACK');
                $this->inTransaction = false;

                if ($e instanceof RetryableDbError && $attempt < $maxAttempts) {
                    usleep(random_int(20_000, 150_000) * $attempt);
                    continue;
                }

                throw $e;
            }

            $this->inTransaction = false;

            try {
                $this->raw('COMMIT');
            } catch (DbError $e) {
                throw new CommitOutcomeUnknown($e->errorCode, 0, $e);
            }

            return $result;
        }
    }

    public function select(string $sql, string $types = '', array $params = []): array
    {
        $rows = $this->quietly(fn () => $this->db->get_results($this->prepare($sql, $types, $params), ARRAY_A));

        if (!is_array($rows) || $this->db->last_error !== '') {
            throw $this->error('select_failed');
        }

        /** @var list<array<string, int|float|string|null>> $rows */
        return $rows;
    }

    public function write(string $sql, string $types = '', array $params = []): int
    {
        $affected = $this->quietly(fn () => $this->db->query($this->prepare($sql, $types, $params)));

        if ($affected === false) {
            throw $this->error('write_failed');
        }

        return (int) $affected;
    }

    public function lastInsertId(): int
    {
        return (int) $this->db->insert_id;
    }

    /**
     * Converts mysqli-style "?" placeholders (types "i", "s", "d") to $wpdb->prepare().
     * Restore SQL never contains a literal "?" or "%", so a plain scan is safe.
     *
     * @param list<int|string|null> $params
     */
    private function prepare(string $sql, string $types, array $params): string
    {
        if (DbDialect::isSqlite()) {
            // Row locks do not exist; BEGIN IMMEDIATE already serialises writers.
            $sql = (string) preg_replace('/\s+(FOR UPDATE|LOCK IN SHARE MODE)\b/i', '', $sql);
        }

        $values = [];
        $i = 0;
        $query = (string) preg_replace_callback('/\?/', static function () use ($types, $params, &$i, &$values): string {
            $value = $params[$i] ?? null;
            $type = $types[$i] ?? 's';
            $i++;

            if ($value === null) {
                return 'NULL'; // $wpdb->prepare() has no NULL placeholder.
            }

            $values[] = $value;

            return match ($type) {
                'i' => '%d',
                'd' => '%f',
                default => '%s',
            };
        }, $sql);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The template is built above from placeholders only.
        return $values === [] ? $query : (string) $this->db->prepare($query, ...$values);
    }

    private function raw(string $sql): void
    {
        if ($this->quietly(fn () => $this->db->query($sql)) === false) {
            throw $this->error('query_failed');
        }
    }

    /**
     * Expected failures (a duplicate idempotency key, a busy database) must not be
     * printed by wpdb; they are reported as exceptions instead.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function quietly(callable $fn): mixed
    {
        $suppress = $this->db->suppress_errors(true);

        try {
            return $fn();
        } finally {
            $this->db->suppress_errors($suppress);
        }
    }

    private function error(string $code): DbError
    {
        $message = (string) $this->db->last_error;

        return match (true) {
            (bool) preg_match('/database is locked|deadlock|lock wait timeout/i', $message) => new RetryableDbError('database_locked'),
            (bool) preg_match('/duplicate entry|unique constraint failed/i', $message) => new DbError($code, DbError::DUPLICATE_KEY),
            default => new DbError($code),
        };
    }
}
