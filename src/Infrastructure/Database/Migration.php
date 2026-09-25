<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

interface Migration
{
    /** Must be idempotent. */
    public function up(\wpdb $db, Tables $t): void;
}
