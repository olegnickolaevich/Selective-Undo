<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

/**
 * Reads a wp_posts row directly from the database, bypassing the object cache.
 * Values are compared in PHP, never in SQL: utf8mb4_*_ci collations treat
 * 'Title' = 'title' and PAD SPACE collations treat 'a' = 'a '.
 */
final class PostRowReader
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    public function read(int $postId): ?PostRow
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                'SELECT ID, post_type, post_status, post_title, post_content, post_excerpt, menu_order
                 FROM %i WHERE ID = %d',
                $this->db->posts,
                $postId
            ),
            ARRAY_A
        );

        return is_array($row) ? PostRow::fromDb($row) : null;
    }
}
