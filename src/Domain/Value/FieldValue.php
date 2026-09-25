<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Value;

/**
 * Immutable typed value of a field.
 *
 * Distinguishes a missing value, null, false, 0, '0', '' and [].
 * Allowed PHP types: null, bool, int, string, array (recursively).
 * Rejected: float, object, resource. Adapters must convert such values
 * (for example to a decimal string) before they reach the journal.
 */
final class FieldValue
{
    public const MAX_DEPTH = 16;
    public const MAX_NODES = 10000;

    private ?string $canonical = null;

    /**
     * @param null|bool|int|string|array<array-key, mixed> $value
     */
    private function __construct(
        public readonly bool $exists,
        public readonly null|bool|int|string|array $value,
    ) {
    }

    public static function missing(): self
    {
        return new self(false, null);
    }

    /**
     * Accepts mixed on purpose: adapters pass data coming from WordPress and an
     * unsupported type must produce a domain error with a reason code, not a TypeError.
     *
     * @throws InvalidFieldValue
     */
    public static function of(mixed $value): self
    {
        $nodes = 0;
        self::assertSupported($value, 0, $nodes);

        /** @var null|bool|int|string|array<array-key, mixed> $value */
        return new self(true, $value);
    }

    /** Canonical byte representation (format c1). */
    public function canonical(): string
    {
        return $this->canonical ??= CanonicalEncoder::encode($this);
    }

    /** Raw 32-byte SHA-256 of the canonical representation. */
    public function hash(): string
    {
        return hash('sha256', $this->canonical(), true);
    }

    public function equals(self $other): bool
    {
        return $this->canonical() === $other->canonical();
    }

    /**
     * Human readable representation for diffs. Never used for comparison.
     */
    public function toDisplayString(): string
    {
        if (!$this->exists) {
            return '';
        }

        return match (true) {
            $this->value === null => '',
            is_bool($this->value) => $this->value ? 'true' : 'false',
            is_int($this->value) => (string) $this->value,
            is_string($this->value) => $this->value,
            default => (string) json_encode($this->value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        };
    }

    public function isBinary(): bool
    {
        return is_string($this->value) && !CanonicalEncoder::isUtf8($this->value);
    }

    /**
     * @throws InvalidFieldValue
     */
    private static function assertSupported(mixed $value, int $depth, int &$nodes): void
    {
        if (++$nodes > self::MAX_NODES) {
            throw new InvalidFieldValue('too_many_nodes');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value)) {
            throw new InvalidFieldValue('float_not_supported');
        }

        if (!is_array($value)) {
            throw new InvalidFieldValue('type_not_supported');
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidFieldValue('too_deep');
        }

        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (!$isList && is_string($key) && !CanonicalEncoder::isUtf8($key)) {
                throw new InvalidFieldValue('map_key_not_utf8');
            }

            self::assertSupported($item, $depth + 1, $nodes);
        }
    }
}
