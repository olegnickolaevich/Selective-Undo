<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Domain\Contracts\WallClock;

final class SystemClock implements WallClock
{
    public function now(): int
    {
        return time();
    }

    public function utcMysql(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    public function siteLocalMysql(): string
    {
        return current_time('mysql');
    }
}
