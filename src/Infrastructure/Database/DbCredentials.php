<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

final readonly class DbCredentials
{
    public function __construct(
        public string $host,
        public string $user,
        public string $password,
        public string $database,
        public ?int $port,
        public ?string $socket,
        public int $clientFlags,
    ) {
    }

    /**
     * Uses wpdb::parse_db_host() so DB_HOST parsing matches WordPress (port, socket, IPv6).
     */
    public static function fromWordPress(\wpdb $wpdb): self
    {
        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
            throw new StorageUnavailable('db_constants_missing');
        }

        $parsed = $wpdb->parse_db_host(DB_HOST);

        if ($parsed === false) {
            throw new StorageUnavailable('db_host_unparseable');
        }

        [$host, $port, $socket, $isIpv6] = $parsed;

        return new self(
            host: $isIpv6 && $host !== null ? '[' . $host . ']' : (string) $host,
            user: (string) DB_USER,
            password: (string) DB_PASSWORD,
            database: (string) DB_NAME,
            port: $port !== null && $port !== '' ? (int) $port : null,
            socket: $socket !== null && $socket !== '' ? (string) $socket : null,
            clientFlags: defined('MYSQL_CLIENT_FLAGS') ? (int) MYSQL_CLIENT_FLAGS : 0,
        );
    }
}
