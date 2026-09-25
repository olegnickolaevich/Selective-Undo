<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Unit;

use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Storage\BlobCodec;
use SelectiveUndo\Tests\TestCase;

final class BlobCodecTest extends TestCase
{
    public function testLargeValuesAreCompressedAndRoundTrip(): void
    {
        $codec = new BlobCodec(2 << 20);
        $big = FieldValue::of(str_repeat("<!-- wp:paragraph --><p>Lorem ipsum</p><!-- /wp:paragraph -->\n", 500));
        $row = $codec->pack($big);
        $this->assertSame('c1+gz', $row['encoding']);
        $this->assertTrue($row['stored_bytes'] < $row['uncompressed_bytes']);
        $this->assertTrue($codec->unpack($row['encoding'], $row['payload'], $row['uncompressed_bytes'], $row['content_hash'])->equals($big));
        $this->assertSame('c1', $codec->pack(FieldValue::of('Hi'))['encoding']);
    }

    public function testIntegrityFailuresAreDetected(): void
    {
        $codec = new BlobCodec(2 << 20);
        $row = $codec->pack(FieldValue::of(str_repeat('abc', 2000)));
        $reason = fn (callable $fn): string => $this->assertThrows(InvalidPayload::class, $fn)->reasonCode;

        $this->assertSame('payload_hash_mismatch', $reason(fn () => $codec->unpack($row['encoding'], $row['payload'], $row['uncompressed_bytes'], str_repeat("\0", 32))));
        $this->assertSame('payload_malformed', $reason(fn () => $codec->unpack($row['encoding'], $row['payload'], 100, $row['content_hash'])), 'declared size lie = decompression bomb guard');
        $this->assertSame('payload_malformed', $reason(fn () => $codec->unpack('c1', 'abc', 3, hash('sha256', 'abc', true))));
        $this->assertSame('payload_unknown_encoding', $reason(fn () => $codec->unpack('c9', 'abc', 3, hash('sha256', 'abc', true))));
        $this->assertSame('payload_too_large', $reason(fn () => (new BlobCodec(10))->pack(FieldValue::of(str_repeat('x', 100)))));
    }
}
