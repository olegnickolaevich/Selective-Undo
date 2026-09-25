<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

/**
 * A restore transaction. Implemented by Infrastructure\Database\RestoreConnection.
 */
interface RestoreTransaction
{
    /**
     * @param list<int|string|null> $params
     *
     * @return list<array<string, int|float|string|null>> Native types (mysqlnd), unlike $wpdb.
     */
    public function select(string $sql, string $types = '', array $params = []): array;

    /**
     * @param list<int|string|null> $params
     *
     * @return int Number of CHANGED rows (MySQL reports 0 when values are identical).
     */
    public function write(string $sql, string $types = '', array $params = []): int;

    public function lastInsertId(): int;
}
