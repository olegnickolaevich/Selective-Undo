<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Infrastructure\Database\RestoreConnection;
use SelectiveUndo\Infrastructure\Database\SchemaManager;
use SelectiveUndo\Infrastructure\Database\StorageUnavailable;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Strict mode requirements. When they are not met, capture may continue but restore
 * is blocked with an explanation; there is no silent fallback to a weaker mode.
 */
final class RestoreReadiness
{
    private const TRANSIENT = 'selective_undo_restore_ready';

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly SchemaManager $schema,
        private readonly RestoreConnection $connection,
    ) {
    }

    /**
     * @return list<string> Blocking reason codes; empty when restore is possible.
     */
    public function blockers(bool $fresh = false): array
    {
        if (!$this->schema->isCurrent()) {
            return ['schema_outdated'];
        }

        $cached = $fresh ? false : get_transient(self::TRANSIENT);

        if (is_array($cached)) {
            return array_values(array_map('strval', $cached));
        }

        $blockers = [];
        $engine = strtolower((string) $this->db->get_var($this->db->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $this->t->posts
        )));

        if ($engine !== 'innodb') {
            $blockers[] = 'posts_table_not_innodb';
        }

        if ($this->schema->verify() !== []) {
            $blockers[] = 'plugin_tables_invalid';
        }

        try {
            $this->connection->ping();
        } catch (StorageUnavailable $e) {
            $blockers[] = $e->reasonCode;
        } catch (\Throwable) {
            $blockers[] = 'restore_connection_failed';
        }

        set_transient(self::TRANSIENT, $blockers, $blockers === [] ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);

        return $blockers;
    }
}
