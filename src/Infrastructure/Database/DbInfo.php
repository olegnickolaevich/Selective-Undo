<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

/**
 * Cached database facts needed on hot paths.
 */
final class DbInfo
{
    private const TRANSIENT = 'selective_undo_db_info';

    /** @var array{max_allowed_packet: int, version: string}|null */
    private ?array $info = null;

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function maxAllowedPacket(): int
    {
        return $this->info()['max_allowed_packet'];
    }

    public function serverVersion(): string
    {
        return $this->info()['version'];
    }

    public function refresh(): void
    {
        delete_transient(self::TRANSIENT);
        $this->info = null;
    }

    /**
     * @return array{max_allowed_packet: int, version: string}
     */
    private function info(): array
    {
        if ($this->info !== null) {
            return $this->info;
        }

        $cached = get_transient(self::TRANSIENT);

        if (is_array($cached) && isset($cached['max_allowed_packet'], $cached['version'])) {
            return $this->info = ['max_allowed_packet' => (int) $cached['max_allowed_packet'], 'version' => (string) $cached['version']];
        }

        $row = $this->db->get_row('SELECT @@max_allowed_packet AS packet, VERSION() AS version', ARRAY_A);
        $info = [
            'max_allowed_packet' => (int) ($row['packet'] ?? 0),
            'version' => (string) ($row['version'] ?? ''),
        ];
        set_transient(self::TRANSIENT, $info, DAY_IN_SECONDS);

        return $this->info = $info;
    }
}
