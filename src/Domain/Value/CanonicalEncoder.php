<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Value;

/**
 * Canonical format c1.
 *
 *   root := {"f":1,"x":0}                  missing value
 *         | {"f":1,"x":1,"v":NODE}         existing value
 *   NODE := ["n"]                          null
 *         | ["b",true|false]               bool
 *         | ["i",<int>]                    int (64-bit)
 *         | ["s","<utf-8>"]                valid UTF-8 string
 *         | ["y","<base64>"]               arbitrary bytes (invalid UTF-8)
 *         | ["l",[NODE,...]]               list, order preserved
 *         | ["m",[["key",NODE],...]]       map, pairs in the original PHP array order
 *
 * Byte equality of canonical strings == strict equality (===) of values.
 * Keys are NOT sorted: PHP arrays are ordered and an exact restore must return
 * the original order. When a field is semantically a set (e.g. term IDs),
 * the adapter sorts it during normalisation.
 */
final class CanonicalEncoder
{
    public const FORMAT = 1;

    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function encode(FieldValue $value): string
    {
        $root = $value->exists
            ? ['f' => self::FORMAT, 'x' => 1, 'v' => self::node($value->value)]
            : ['f' => self::FORMAT, 'x' => 0];

        return json_encode($root, self::FLAGS);
    }

    /**
     * @throws InvalidPayload
     */
    public static function decode(string $canonical, int $maxBytes): FieldValue
    {
        if (strlen($canonical) > $maxBytes) {
            throw new InvalidPayload('payload_too_large');
        }

        try {
            $root = json_decode(
                $canonical,
                true,
                FieldValue::MAX_DEPTH * 4 + 8,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (\JsonException) {
            throw new InvalidPayload('payload_malformed');
        }

        if (!is_array($root) || ($root['f'] ?? null) !== self::FORMAT) {
            throw new InvalidPayload('payload_unknown_format');
        }

        try {
            $value = match ($root['x'] ?? null) {
                0 => FieldValue::missing(),
                1 => FieldValue::of(self::fromNode($root['v'] ?? null)),
                default => throw new InvalidPayload('payload_malformed'),
            };
        } catch (InvalidFieldValue) {
            throw new InvalidPayload('payload_malformed');
        }

        // Reject syntactically valid but non-canonical input:
        // re-encoding must reproduce the exact same bytes.
        if (self::encode($value) !== $canonical) {
            throw new InvalidPayload('payload_not_canonical');
        }

        return $value;
    }

    public static function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    /**
     * @return array<int, mixed>
     */
    private static function node(mixed $value): array
    {
        return match (true) {
            $value === null => ['n'],
            is_bool($value) => ['b', $value],
            is_int($value) => ['i', $value],
            is_string($value) => self::isUtf8($value)
                ? ['s', $value]
                : ['y', base64_encode($value)],
            is_array($value) => array_is_list($value)
                ? ['l', array_map(self::node(...), $value)]
                : ['m', self::pairs($value)],
            default => throw new InvalidFieldValue('type_not_supported'),
        };
    }

    /**
     * @param array<array-key, mixed> $map
     *
     * @return list<array{0: string, 1: array<int, mixed>}>
     */
    private static function pairs(array $map): array
    {
        $pairs = [];

        foreach ($map as $key => $item) {
            $pairs[] = [(string) $key, self::node($item)];
        }

        return $pairs;
    }

    /**
     * @throws InvalidPayload
     */
    private static function fromNode(mixed $node): mixed
    {
        if (!is_array($node) || !array_is_list($node) || $node === []) {
            throw new InvalidPayload('payload_malformed');
        }

        $tag = $node[0];
        $arg = $node[1] ?? null;
        $arity = count($node);

        return match (true) {
            $tag === 'n' && $arity === 1 => null,
            $tag === 'b' && $arity === 2 && is_bool($arg) => $arg,
            $tag === 'i' && $arity === 2 && is_int($arg) => $arg,
            $tag === 's' && $arity === 2 && is_string($arg) => $arg,
            $tag === 'y' && $arity === 2 && is_string($arg) => self::bytes($arg),
            $tag === 'l' && $arity === 2 && is_array($arg) && array_is_list($arg)
                => array_map(self::fromNode(...), $arg),
            $tag === 'm' && $arity === 2 && is_array($arg) && array_is_list($arg)
                => self::mapFromPairs($arg),
            default => throw new InvalidPayload('payload_malformed'),
        };
    }

    private static function bytes(string $base64): string
    {
        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            throw new InvalidPayload('payload_malformed');
        }

        return $decoded;
    }

    /**
     * @param list<mixed> $pairs
     *
     * @return array<array-key, mixed>
     */
    private static function mapFromPairs(array $pairs): array
    {
        $map = [];

        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0] ?? null)) {
                throw new InvalidPayload('payload_malformed');
            }

            if (array_key_exists($pair[0], $map)) {
                throw new InvalidPayload('payload_malformed');
            }

            $map[$pair[0]] = self::fromNode($pair[1]);
        }

        return $map;
    }
}
