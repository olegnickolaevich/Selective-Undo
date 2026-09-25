<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Unit;

use SelectiveUndo\Domain\Value\CanonicalEncoder;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidFieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Tests\TestCase;

final class FieldValueTest extends TestCase
{
    private const MB = 1 << 20;

    public function testSpecialValuesAreDistinct(): void
    {
        $values = [FieldValue::missing(), FieldValue::of(null), FieldValue::of(''), FieldValue::of(0),
            FieldValue::of('0'), FieldValue::of(false), FieldValue::of([])];
        $hashes = array_map(static fn (FieldValue $v): string => $v->hash(), $values);
        $this->assertCount(count($values), array_unique($hashes));

        foreach ($values as $v) {
            $decoded = CanonicalEncoder::decode($v->canonical(), self::MB);
            $this->assertTrue($decoded->equals($v));
            $this->assertSame($v->exists, $decoded->exists);
            $this->assertSame($v->value, $decoded->value);
        }
    }

    public function testInvalidUtf8IsStoredAsBytesAndRoundTripsExactly(): void
    {
        $bin = "abc\xff\xfe\x00z";
        $v = FieldValue::of($bin);
        $this->assertTrue(str_contains($v->canonical(), '["y",'));
        $this->assertSame($bin, CanonicalEncoder::decode($v->canonical(), self::MB)->value);
        $this->assertTrue($v->isBinary());
    }

    public function testUtf8SpecialCharactersRoundTrip(): void
    {
        $s = "Контакты — 😀 \\ \"q\" </script>\u{2028}";
        $this->assertSame($s, CanonicalEncoder::decode(FieldValue::of($s)->canonical(), self::MB)->value);
    }

    public function testNoNormalisation(): void
    {
        $this->assertFalse(FieldValue::of("<p>a</p>")->equals(FieldValue::of("<p>a</p>\n")));
        $this->assertFalse(FieldValue::of('Title')->equals(FieldValue::of('title')));
        $this->assertFalse(FieldValue::of('a ')->equals(FieldValue::of('a')));
        $this->assertFalse(FieldValue::of(3)->equals(FieldValue::of('3')));
    }

    public function testUnsupportedTypesAreRejectedWithReason(): void
    {
        $this->assertSame('float_not_supported', $this->assertThrows(InvalidFieldValue::class, fn () => FieldValue::of(1.5))->reasonCode);
        $this->assertSame('float_not_supported', $this->assertThrows(InvalidFieldValue::class, fn () => FieldValue::of(['x' => [1.0]]))->reasonCode);
        $this->assertSame('type_not_supported', $this->assertThrows(InvalidFieldValue::class, fn () => FieldValue::of(new \stdClass()))->reasonCode);
        $deep = 'v';

        for ($i = 0; $i < 20; $i++) {
            $deep = [$deep];
        }

        $this->assertSame('too_deep', $this->assertThrows(InvalidFieldValue::class, fn () => FieldValue::of($deep))->reasonCode);
        $this->assertSame('map_key_not_utf8', $this->assertThrows(InvalidFieldValue::class, fn () => FieldValue::of(["\xff" => 1]))->reasonCode);
    }

    public function testKeyOrderIsPreserved(): void
    {
        $a = FieldValue::of([1 => 'a', 0 => 'b']);
        $this->assertFalse($a->equals(FieldValue::of([0 => 'b', 1 => 'a'])));
        $this->assertSame([1 => 'a', 0 => 'b'], CanonicalEncoder::decode($a->canonical(), self::MB)->value);
        $m = ['z' => 1, 'a' => ['x', 'y'], '10' => null, '-1' => true];
        $this->assertSame($m, CanonicalEncoder::decode(FieldValue::of($m)->canonical(), self::MB)->value);
    }

    public function testNonCanonicalAndCorruptPayloadsAreRejected(): void
    {
        $cases = [
            '{"f":1, "x":1,"v":["s","a"]}' => 'payload_not_canonical',
            '{"f":1,"x":1,"v":["m",[["0",["s","a"]],["1",["s","b"]]]]}' => 'payload_not_canonical',
            '{"f":1,"x":1,"v":["i",1.0]}' => 'payload_malformed',
            '{"f":1,"x":1,"v":["i",99999999999999999999]}' => 'payload_malformed',
            '{"f":2,"x":0}' => 'payload_unknown_format',
            '{"f":1,"x":1,"v":["y","@@"]}' => 'payload_malformed',
            '{"f":1,"x":1,"v":["m",[["a",["n"]],["a",["n"]]]]}' => 'payload_malformed',
            'not json' => 'payload_malformed',
        ];

        foreach ($cases as $payload => $reason) {
            $e = $this->assertThrows(InvalidPayload::class, fn () => CanonicalEncoder::decode($payload, self::MB), $payload);
            $this->assertSame($reason, $e->reasonCode, $payload);
        }

        $this->assertSame('payload_too_large', $this->assertThrows(InvalidPayload::class, fn () => CanonicalEncoder::decode(str_repeat('x', 100), 10))->reasonCode);
    }

    public function testCanonicalVectors(): void
    {
        $vectors = [
            ['{"f":1,"x":0}', '0877130f9a7afd1e92bb850b51f0919d3f5fd721d014068995ac7817ea66b39e', FieldValue::missing()],
            ['{"f":1,"x":1,"v":["n"]}', '885a1f0bbc5108f15f776e9e395031f885c6316b4e6d1c62d359bf49c3576309', FieldValue::of(null)],
            ['{"f":1,"x":1,"v":["i",0]}', '459ee45b6b4a6327f415d5e1c6a1515df0229d144cb3ee905aa93e6f4ca6f7c9', FieldValue::of(0)],
            ['{"f":1,"x":1,"v":["s","Контакты"]}', 'ed461417b3f0b0fffcd050e9224848adc33ada87a69fd266e40f0ea727a41951', FieldValue::of('Контакты')],
            ['{"f":1,"x":1,"v":["y","/w=="]}', '59143890e736c34e640bb9c6f764ffa72c572353f5182b575d7f5e1706d4189f', FieldValue::of("\xff")],
        ];

        foreach ($vectors as [$canonical, $hash, $value]) {
            $this->assertSame($canonical, $value->canonical());
            $this->assertSame($hash, bin2hex($value->hash()));
        }
    }
}
