<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Value\FieldValue;

/**
 * Snapshot of the tracked wp_posts columns, normalised by the adapter.
 */
final readonly class PostRow implements ObjectSnapshot
{
    public const TRACKED_FIELDS = ['post_title', 'post_content', 'post_excerpt', 'menu_order'];

    public function __construct(
        public int $id,
        public string $postType,
        public string $postStatus,
        public string $postTitle,
        public string $postContent,
        public string $postExcerpt,
        public int $menuOrder,
    ) {
    }

    /**
     * @param array<string, int|float|string|null> $row A database row; $wpdb returns strings, mysqlnd native types.
     */
    public static function fromDb(array $row): self
    {
        return new self(
            id: (int) $row['ID'],
            postType: (string) $row['post_type'],
            postStatus: (string) $row['post_status'],
            postTitle: (string) $row['post_title'],
            postContent: (string) $row['post_content'],
            postExcerpt: (string) $row['post_excerpt'],
            // Normalisation: $wpdb returns "0" while WP_Post exposes int 0.
            menuOrder: (int) $row['menu_order'],
        );
    }

    public static function fromPost(\WP_Post $post): self
    {
        return new self(
            id: $post->ID,
            postType: $post->post_type,
            postStatus: $post->post_status,
            postTitle: (string) $post->post_title,
            postContent: (string) $post->post_content,
            postExcerpt: (string) $post->post_excerpt,
            menuOrder: (int) $post->menu_order,
        );
    }

    public function objectId(): int
    {
        return $this->id;
    }

    public function objectSubtype(): string
    {
        return $this->postType;
    }

    public function field(string $fieldKey): FieldValue
    {
        return match ($fieldKey) {
            'post_title' => FieldValue::of($this->postTitle),
            'post_content' => FieldValue::of($this->postContent),
            'post_excerpt' => FieldValue::of($this->postExcerpt),
            'menu_order' => FieldValue::of($this->menuOrder),
            default => throw new \InvalidArgumentException('Unsupported field'),
        };
    }

    public function sameTrackedValues(self $other): bool
    {
        foreach (self::TRACKED_FIELDS as $key) {
            if (!$this->field($key)->equals($other->field($key))) {
                return false;
            }
        }

        return true;
    }
}
