<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Value\CanonicalEncoder;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Database\DbDialect;

/**
 * Packs a canonical value into a blobs table row and unpacks it with integrity checks.
 */
final class BlobCodec
{
    public const ENCODING_PLAIN = 'c1';
    public const ENCODING_GZIP = 'c1+gz';
    /** Suffix for base64 transport on SQLite. */
    public const BASE64 = '+b64';

    private const COMPRESS_FROM_BYTES = 1024;
    private const MIN_SAVING_RATIO = 0.9;

    public function __construct(private readonly int $maxPayloadBytes)
    {
    }

    /**
     * @return array{content_hash: string, encoding: string, payload: string, uncompressed_bytes: int, stored_bytes: int}
     *
     * @throws InvalidPayload
     */
    public function pack(FieldValue $value): array
    {
        $canonical = $value->canonical();
        $length = strlen($canonical);

        if ($length > $this->maxPayloadBytes) {
            throw new InvalidPayload('payload_too_large');
        }

        $encoding = self::ENCODING_PLAIN;
        $payload = $canonical;

        if ($length >= self::COMPRESS_FROM_BYTES && function_exists('gzencode')) {
            $compressed = gzencode($canonical, 6);

            if ($compressed !== false && strlen($compressed) < $length * self::MIN_SAVING_RATIO) {
                $encoding = self::ENCODING_GZIP;
                $payload = $compressed;
            }
        }

        if (DbDialect::isSqlite()) {
            // The SQLite driver passes queries as text: keep NUL bytes out of the SQL.
            $payload = base64_encode($payload);
            $encoding .= self::BASE64;
        }

        return [
            'content_hash' => hash('sha256', $canonical, true),
            'encoding' => $encoding,
            'payload' => $payload,
            'uncompressed_bytes' => $length,
            'stored_bytes' => strlen($payload),
        ];
    }

    /**
     * @throws InvalidPayload
     */
    public function unpack(string $encoding, string $payload, int $uncompressedBytes, string $expectedHash): FieldValue
    {
        if ($uncompressedBytes < 0 || $uncompressedBytes > $this->maxPayloadBytes) {
            throw new InvalidPayload('payload_too_large');
        }

        if (str_ends_with($encoding, self::BASE64)) {
            $decoded = base64_decode($payload, true);

            if ($decoded === false) {
                throw new InvalidPayload('payload_unknown_encoding');
            }

            $payload = $decoded;
            $encoding = substr($encoding, 0, -strlen(self::BASE64));
        }

        $canonical = match ($encoding) {
            self::ENCODING_PLAIN => $payload,
            self::ENCODING_GZIP => $this->inflate($payload, $uncompressedBytes),
            default => throw new InvalidPayload('payload_unknown_encoding'),
        };

        if (strlen($canonical) !== $uncompressedBytes) {
            throw new InvalidPayload('payload_size_mismatch');
        }

        if (!hash_equals($expectedHash, hash('sha256', $canonical, true))) {
            throw new InvalidPayload('payload_hash_mismatch');
        }

        return CanonicalEncoder::decode($canonical, $this->maxPayloadBytes);
    }

    private function inflate(string $payload, int $uncompressedBytes): string
    {
        if (!function_exists('gzdecode')) {
            throw new InvalidPayload('payload_codec_unavailable');
        }

        // The decompression limit equals the declared size (decompression bomb protection).
        // gzdecode() returns false and emits a warning when max_length is exceeded,
        // so the warning is intercepted locally instead of being hidden with @.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped to this call and restored in finally.
        set_error_handler(static fn (): bool => true);

        try {
            $result = gzdecode($payload, max(1, $uncompressedBytes));
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new InvalidPayload('payload_malformed');
        }

        return $result;
    }
}
