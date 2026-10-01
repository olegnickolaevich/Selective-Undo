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
    public const TRACKED_FIELDS = [
        'post_title', 'post_content', 'post_excerpt', 'menu_order',
        'post_name', 'post_status', 'post_date', 'post_author', 'post_parent', 'comment_status', 'ping_status',
    ];

    /** Columns read for a snapshot. */
    public const COLUMNS = 'ID, post_type, post_status, post_title, post_content, post_excerpt, menu_order,
        post_name, post_date, post_date_gmt, post_author, post_parent, comment_status, ping_status';

    public function __construct(
        public int $id,
        public string $postType,
        public string $postStatus,
        public string $postTitle,
        public string $postContent,
        public string $postExcerpt,
        public int $menuOrder,
        public string $postName = '',
        public string $postDate = '0000-00-00 00:00:00',
        public string $postDateGmt = '0000-00-00 00:00:00',
        public int $postAuthor = 0,
        public int $postParent = 0,
        public string $commentStatus = '',
        public string $pingStatus = '',
    ) {
    }

    /**
     * @param array<string, int|float|string|null> $row A database row; $wpdb returns strings, mysqlnd native types.
     *                                                 Revision rows read by the importer carry only some columns.
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
            postName: (string) ($row['post_name'] ?? ''),
            postDate: (string) ($row['post_date'] ?? '0000-00-00 00:00:00'),
            postDateGmt: (string) ($row['post_date_gmt'] ?? '0000-00-00 00:00:00'),
            postAuthor: (int) ($row['post_author'] ?? 0),
            postParent: (int) ($row['post_parent'] ?? 0),
            commentStatus: (string) ($row['comment_status'] ?? ''),
            pingStatus: (string) ($row['ping_status'] ?? ''),
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
            postName: (string) $post->post_name,
            postDate: (string) $post->post_date,
            postDateGmt: (string) $post->post_date_gmt,
            postAuthor: (int) $post->post_author,
            postParent: (int) $post->post_parent,
            commentStatus: (string) $post->comment_status,
            pingStatus: (string) $post->ping_status,
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
            'post_name' => FieldValue::of($this->postName),
            'post_status' => FieldValue::of($this->postStatus),
            // Local and UTC time are one field: they are always written together.
            'post_date' => FieldValue::of(['gmt' => $this->postDateGmt, 'local' => $this->postDate]),
            'post_author' => FieldValue::of($this->postAuthor),
            'post_parent' => FieldValue::of($this->postParent),
            'comment_status' => FieldValue::of($this->commentStatus),
            'ping_status' => FieldValue::of($this->pingStatus),
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
