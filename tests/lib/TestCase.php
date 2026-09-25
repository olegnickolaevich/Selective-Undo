<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests;

/**
 * Minimal xUnit-style base class. PHPUnit is intentionally not required so the
 * suite runs anywhere PHP runs; assertions mirror PHPUnit names.
 */
abstract class TestCase
{
    public int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected !== $actual) {
            throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . 'Expected ' . self::export($expected) . ', got ' . self::export($actual));
        }
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($unexpected === $actual) {
            throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . 'Did not expect ' . self::export($actual));
        }
    }

    protected function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->assertSame(true, $actual, $message);
    }

    protected function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->assertSame(false, $actual, $message);
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($actual === null) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected a non-null value');
        }
    }

    /**
     * @param countable|array<mixed> $actual
     */
    protected function assertCount(int $expected, \Countable|array $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message !== '' ? $message : 'Unexpected count');
    }

    /**
     * @param array<mixed> $haystack
     */
    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (!in_array($needle, $haystack, true)) {
            throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . self::export($needle) . ' not found in ' . self::export($haystack));
        }
    }

    /**
     * @param array<mixed> $haystack
     */
    protected function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (in_array($needle, $haystack, true)) {
            throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . self::export($needle) . ' unexpectedly found');
        }
    }

    /**
     * @param class-string $class
     */
    protected function assertInstanceOf(string $class, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if (!$actual instanceof $class) {
            throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . 'Expected instance of ' . $class . ', got ' . get_debug_type($actual));
        }
    }

    /**
     * Asserts that $fn throws $class; returns the exception for further checks.
     *
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function assertThrows(string $class, callable $fn, string $message = ''): \Throwable
    {
        $this->assertions++;

        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }

            throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . 'Expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
        }

        throw new AssertionFailed(($message !== '' ? $message . "\n" : '') . 'Expected exception ' . $class . ' was not thrown');
    }

    protected static function export(mixed $value): string
    {
        if (is_string($value) && !preg_match('//u', $value)) {
            return 'binary(' . bin2hex($value) . ')';
        }

        $out = var_export($value, true);

        return strlen($out) > 600 ? substr($out, 0, 600) . '…' : $out;
    }
}
