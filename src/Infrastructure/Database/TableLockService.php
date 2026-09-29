<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

use SelectiveUndo\Domain\Contracts\LockService;

/**
 * Lock rows with an expiry, for databases without named locks (SQLite).
 * A crashed holder releases the lock when it expires.
 */
final class TableLockService implements LockService
{
    private const TTL_SECONDS = 60;

    /** @var array<string, string> name => owner token */
    private array $held = [];

    public function __construct(private readonly \wpdb $db, private readonly Tables $t)
    {
    }

    public function acquire(string $name, int $waitSeconds): bool
    {
        $owner = wp_generate_uuid4();
        $deadline = microtime(true) + $waitSeconds;
        $suppress = $this->db->suppress_errors(true); // a taken lock is a duplicate key, not an error

        try {
            do {
                $this->db->query($this->db->prepare(
                    "DELETE FROM {$this->t->locks} WHERE name = %s AND expires_at < %s",
                    $name,
                    gmdate('Y-m-d H:i:s')
                ));
                $ok = $this->db->query($this->db->prepare(
                    "INSERT INTO {$this->t->locks} (name, owner, expires_at) VALUES (%s, %s, %s)",
                    $name,
                    $owner,
                    gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS)
                ));

                if ($ok === 1) {
                    $this->held[$name] = $owner;

                    return true;
                }

                usleep(100_000);
            } while (microtime(true) < $deadline);
        } finally {
            $this->db->suppress_errors($suppress);
        }

        return false;
    }

    public function release(string $name): void
    {
        if (isset($this->held[$name])) {
            $this->db->query($this->db->prepare("DELETE FROM {$this->t->locks} WHERE name = %s AND owner = %s", $name, $this->held[$name]));
            unset($this->held[$name]);
        }
    }
}
