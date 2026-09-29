<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

use SelectiveUndo\Domain\Contracts\LockService;

/** MySQL named locks, scoped to the table prefix (several sites can share a database). */
final class MysqlLockService implements LockService
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    public function acquire(string $name, int $waitSeconds): bool
    {
        return (int) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', $this->scoped($name), $waitSeconds)) === 1;
    }

    public function release(string $name): void
    {
        $this->db->query($this->db->prepare('SELECT RELEASE_LOCK(%s)', $this->scoped($name)));
    }

    private function scoped(string $name): string
    {
        // MySQL limits lock names to 64 characters.
        return substr('sundo_' . substr(md5($this->db->prefix), 0, 8) . '_' . $name, 0, 64);
    }
}
