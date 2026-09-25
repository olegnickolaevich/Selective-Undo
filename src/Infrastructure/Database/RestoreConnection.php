<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

use SelectiveUndo\Domain\Contracts\RestoreTransaction;

/**
 * Dedicated connection to the primary database, used only for restore transactions
 * (see ADR-002 in the specification notes).
 *
 * Why not $wpdb:
 *  - on error 2006 wpdb::query() reconnects and re-runs the statement OUTSIDE the transaction;
 *  - db.php drop-ins (HyperDB, LudicrousDB) may route SELECT ... FOR UPDATE to a replica;
 *  - SET SESSION innodb_lock_wait_timeout must not affect the rest of the WordPress request.
 *
 * mysqli_report() is a process-wide driver setting. wpdb sets MYSQLI_REPORT_OFF, while
 * PHP 8.1+ defaults to exceptions. This class never changes it and works in both modes.
 */
final class RestoreConnection implements RestoreTransaction
{
    private const RETRYABLE = [1205 => 'lock_wait_timeout', 1213 => 'deadlock'];
    private const CONNECTION_LOST = [2006, 2013];

    private ?\mysqli $link = null;
    private bool $inTransaction = false;

    /**
     * @param \Closure(self): void|null $verify Called once per new connection (same-database check).
     */
    public function __construct(
        private readonly \Closure $credentials,
        private readonly string $charset,
        private readonly int $lockWaitTimeoutSeconds = 5,
        private readonly ?\Closure $verify = null,
    ) {
    }

    /**
     * Runs $fn in a transaction. Retries only on deadlock / lock wait timeout, when the
     * server guarantees a rollback. A failed COMMIT is never retried.
     *
     * @template T
     *
     * @param callable(RestoreTransaction): T $fn
     *
     * @return T
     */
    public function transactional(callable $fn, int $maxAttempts = 3): mixed
    {
        if ($this->inTransaction) {
            throw new \LogicException('Nested restore transactions are not allowed.');
        }

        for ($attempt = 1; ; $attempt++) {
            $this->execute('START TRANSACTION');
            $this->inTransaction = true;

            try {
                $result = $fn($this);
            } catch (\Throwable $e) {
                $this->rollbackQuietly();

                if ($e instanceof RetryableDbError && $attempt < $maxAttempts) {
                    usleep(random_int(20_000, 150_000) * $attempt);
                    continue;
                }

                throw $e;
            }

            try {
                $this->execute('COMMIT');
            } catch (DbError $e) {
                $this->inTransaction = false;
                $this->drop();

                throw new CommitOutcomeUnknown($e->errorCode, 0, $e);
            }

            $this->inTransaction = false;

            return $result;
        }
    }

    public function select(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->prepare($sql, $types, $params);
        $result = $stmt->get_result();

        if ($result === false) {
            $errno = $stmt->errno;
            $stmt->close();

            throw $this->error($errno, 'select_failed');
        }

        /** @var list<array<string, int|float|string|null>> $rows */
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    public function write(string $sql, string $types = '', array $params = []): int
    {
        $stmt = $this->prepare($sql, $types, $params);
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    public function lastInsertId(): int
    {
        return (int) $this->link()->insert_id;
    }

    /**
     * Opens the connection and runs the verification callback.
     */
    public function ping(): void
    {
        $this->link();
    }

    public function close(): void
    {
        $this->drop();
    }

    /**
     * @param list<int|string|null> $params
     */
    private function prepare(string $sql, string $types, array $params): \mysqli_stmt
    {
        $link = $this->link();

        try {
            $stmt = $link->prepare($sql);

            if ($stmt === false) {
                throw $this->error($link->errno, 'prepare_failed');
            }

            if ($params !== [] && !$stmt->bind_param($types, ...$params)) {
                throw $this->error($stmt->errno, 'bind_failed');
            }

            if (!$stmt->execute()) {
                $errno = $stmt->errno;
                $stmt->close();

                throw $this->error($errno, 'execute_failed');
            }
        } catch (\mysqli_sql_exception $e) {
            throw $this->error((int) $e->getCode(), 'execute_failed');
        }

        return $stmt;
    }

    private function execute(string $sql): void
    {
        $link = $this->link();

        try {
            $ok = $link->query($sql) !== false;
        } catch (\mysqli_sql_exception $e) {
            throw $this->error((int) $e->getCode(), 'query_failed');
        }

        if (!$ok) {
            throw $this->error($link->errno, 'query_failed');
        }
    }

    private function link(): \mysqli
    {
        if ($this->link !== null) {
            return $this->link;
        }

        if (!function_exists('mysqli_init')) {
            throw new StorageUnavailable('mysqli_unavailable');
        }

        $link = mysqli_init();

        if ($link === false) {
            throw new StorageUnavailable('restore_connection_failed');
        }

        $link->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        /** @var DbCredentials $c */
        $c = ($this->credentials)();

        try {
            // @: with MYSQLI_REPORT_OFF real_connect() emits a warning containing host and user.
            $ok = @$link->real_connect($c->host, $c->user, $c->password, $c->database, $c->port, $c->socket, $c->clientFlags);
        } catch (\mysqli_sql_exception) {
            $ok = false; // the exception text contains credentials: never rethrow it
        }

        if (!$ok) {
            throw new StorageUnavailable('restore_connection_failed');
        }

        if (!$link->set_charset($this->charset)) {
            throw new StorageUnavailable('restore_connection_charset');
        }

        $this->link = $link;

        try {
            $this->execute(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $this->lockWaitTimeoutSeconds));
            // Strict mode: an invalid value must fail instead of being silently truncated.
            $this->execute("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')");
            $this->execute("SET SESSION time_zone = '+00:00'");

            if ($this->verify !== null) {
                ($this->verify)($this);
            }
        } catch (\Throwable $e) {
            $this->drop();

            throw $e;
        }

        return $link;
    }

    private function rollbackQuietly(): void
    {
        $this->inTransaction = false;

        if ($this->link === null) {
            return;
        }

        try {
            $ok = $this->link->query('ROLLBACK') !== false;
        } catch (\mysqli_sql_exception) {
            $ok = false;
        }

        if (!$ok) {
            $this->drop(); // unknown state: closing the connection makes the server roll back
        }
    }

    private function drop(): void
    {
        if ($this->link !== null) {
            try {
                @$this->link->close();
            } catch (\Throwable) {
                // already closed
            }
        }

        $this->link = null;
    }

    private function error(int $errno, string $code): DbError
    {
        if (isset(self::RETRYABLE[$errno])) {
            return new RetryableDbError(self::RETRYABLE[$errno], $errno);
        }

        if (in_array($errno, self::CONNECTION_LOST, true)) {
            $this->drop();

            return new ConnectionLost('connection_lost', $errno);
        }

        // MySQL error texts may contain fragments of data: only the code is kept.
        return new DbError($code, $errno);
    }
}
