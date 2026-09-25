<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Storage;

use SelectiveUndo\Domain\Value\CanonicalEncoder;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;

/**
 * Packs a canonical value into a blobs table row and unpacks it with integrity checks.
 */
final class BlobCodec
{
    public const ENCODING_PLAIN = 'c1';
    public const ENCODING_GZIP = 'c1+gz';

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
