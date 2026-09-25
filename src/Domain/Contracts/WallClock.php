<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

interface WallClock
{
    /** Unix timestamp. */
    public function now(): int;

    /** 'Y-m-d H:i:s' in UTC. */
    public function utcMysql(int $offsetSeconds = 0): string;

    /** 'Y-m-d H:i:s' in the site time zone (like post_modified). */
    public function siteLocalMysql(): string;
}
