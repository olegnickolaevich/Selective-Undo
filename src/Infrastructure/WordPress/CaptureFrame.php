<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

/**
 * Capture state of one wp_insert_post() call in update mode.
 * Mutable: "after" is filled later, in clean_post_cache.
 */
final class CaptureFrame
{
    public ?PostRow $after = null;

    public function __construct(
        public readonly int $postId,
        public readonly int $sequence,
        public readonly PostRow $before,
    ) {
    }
}
