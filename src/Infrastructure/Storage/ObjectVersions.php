<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Infrastructure\Database\DbDialect;
use SelectiveUndo\Infrastructure\Database\StorageError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Per-object version numbers (object_seq): the order of an object's history. New
 * changes take numbers from a counter per object; history added before the existing
 * one (imported revisions) takes numbers below the smallest, down to negative ones.
 */
final class ObjectVersions
{
    public function __construct(private readonly \wpdb $db, private readonly Tables $t)
    {
    }

    /**
     * Reserves $count consecutive numbers on the WordPress connection (capture path,
     * autocommit: restores write their own journal rows through reserveTx()).
     *
     * @return int The first reserved number.
     */
    public function reserve(ObjectRef $object, int $count = 1): int
    {
        if (DbDialect::isSqlite()) {
            // No LAST_INSERT_ID(expr): bump and read back inside one write transaction.
            $this->db->query('START TRANSACTION');

            try {
                $last = $this->bumpAndRead($object, $count);
                $this->db->query('COMMIT');
            } catch (\Throwable $e) {
                $this->db->query('ROLLBACK');

                throw $e;
            }

            return $last - $count + 1;
        }

        // One atomic statement; LAST_INSERT_ID(expr) is per connection.
        $ok = $this->db->query($this->db->prepare(
            "INSERT INTO {$this->t->objectVersions} (object_type, object_id, last_seq) VALUES (%s, %d, LAST_INSERT_ID(%d))
             ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + %d)",
            $object->type,
            $object->id,
            $count,
            $count
        ));

        if ($ok === false) {
            throw new StorageError('object_version_failed');
        }

        return (int) $this->db->get_var('SELECT LAST_INSERT_ID()') - $count + 1;
    }

    /**
     * The same inside a restore transaction: the counter row stays locked until it ends.
     */
    public function reserveTx(RestoreTransaction $tx, ObjectRef $object, int $count = 1): int
    {
        $tx->write(
            "INSERT INTO `{$this->t->objectVersions}` (object_type, object_id, last_seq) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_seq = last_seq + ?",
            'siii',
            [$object->type, $object->id, $count, $count]
        );
        $row = $tx->select(
            "SELECT last_seq FROM `{$this->t->objectVersions}` WHERE object_type = ? AND object_id = ?",
            'si',
            [$object->type, $object->id]
        );

        if (!isset($row[0]['last_seq'])) {
            throw new StorageError('object_version_failed');
        }

        return (int) $row[0]['last_seq'] - $count + 1;
    }

    /**
     * Numbers placed before the object's existing history. Called under the object's
     * import lock; new changes recorded meanwhile take numbers above, so they never meet.
     *
     * @return int The first number; the last one, $first + $count - 1, is below every existing one.
     */
    public function reserveBefore(ObjectRef $object, int $count): int
    {
        $min = $this->db->get_var($this->db->prepare(
            "SELECT MIN(object_seq) FROM {$this->t->changes} WHERE object_type = %s AND object_id = %d",
            $object->type,
            $object->id
        ));

        return ($min === null ? 1 : (int) $min) - $count;
    }

    private function bumpAndRead(ObjectRef $object, int $count): int
    {
        $ok = $this->db->query($this->db->prepare(
            "INSERT INTO {$this->t->objectVersions} (object_type, object_id, last_seq) VALUES (%s, %d, %d)
             ON DUPLICATE KEY UPDATE last_seq = last_seq + %d",
            $object->type,
            $object->id,
            $count,
            $count
        ));
        $last = $ok === false ? null : $this->db->get_var($this->db->prepare(
            "SELECT last_seq FROM {$this->t->objectVersions} WHERE object_type = %s AND object_id = %d",
            $object->type,
            $object->id
        ));

        if ($last === null) {
            throw new StorageError('object_version_failed');
        }

        return (int) $last;
    }
}
