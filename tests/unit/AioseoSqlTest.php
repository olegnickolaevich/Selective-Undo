<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Unit;

use SelectiveUndo\Infrastructure\Integrations\AioseoAdapter;
use SelectiveUndo\Tests\TestCase;

final class AioseoSqlTest extends TestCase
{
    public function testPostOfAnAioseoWriteIsFound(): void
    {
        $of = static fn (string $sql): ?int => AioseoAdapter::writtenPostId($sql, 'wp_aioseo_posts');

        // As AIOSEO writes on a web request (integers bare) and in WP-CLI (quoted).
        $this->assertSame(3628, $of("UPDATE wp_aioseo_posts\nSET `id` = 1, `post_id` = 3628, `title` = 'A'\nWHERE 1 = 1 AND\n\t`id` = 1"));
        $this->assertSame(3628, $of("INSERT INTO wp_aioseo_posts SET `id` = NULL, `post_id` = '3628', `title` = 'A'"));
        $this->assertNull($of("SELECT * FROM wp_aioseo_posts WHERE `post_id` = '3628'"));
        $this->assertNull($of("UPDATE wp_aioseo_posts SET `images` = NULL"), 'a bulk reset of other columns');
        $this->assertNull($of("UPDATE wp_postmeta SET meta_value = 'x' WHERE post_id = 5"));
    }
}
