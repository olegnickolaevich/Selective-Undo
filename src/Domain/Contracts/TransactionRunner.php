<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

/**
 * Runs restore transactions. Statements outside transactional() run in autocommit.
 */
interface TransactionRunner extends RestoreTransaction
{
    public const DEDICATED = 'dedicated';
    public const COMPAT = 'compat';

    /**
     * Runs $fn inside one transaction and commits, retrying on deadlocks.
     *
     * @template T
     *
     * @param callable(RestoreTransaction): T $fn
     *
     * @return T
     */
    public function transactional(callable $fn, int $maxAttempts = 3): mixed;

    /** Throws StorageUnavailable when restoring is impossible. */
    public function ping(): void;

    /** DEDICATED (own MySQL connection, strict) or COMPAT (shared $wpdb connection). */
    public function mode(): string;
}
