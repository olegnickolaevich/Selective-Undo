<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Presentation\Admin\LastChangeColumn;
use SelectiveUndo\Tests\IntegrationTestCase;

final class LastChangeColumnTest extends IntegrationTestCase
{
    public function testAllRowsAreReadWithOneQuery(): void
    {
        global $wp_query;
        $ids = [];

        foreach (['A', 'B', 'C'] as $title) {
            $id = $this->createPost(['post_title' => $title]);
            wp_update_post(['ID' => $id, 'post_title' => $title . ' edited']);
            $this->endRequest();
            $ids[] = $id;
        }

        $untouched = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Untracked before']);
        $this->truncateChangesOf($untouched);
        $ids[] = $untouched;

        $previous = $wp_query;
        $wp_query = new \WP_Query();
        $wp_query->posts = array_map('get_post', $ids);
        $column = new LastChangeColumn('page', $this->s->journalReader(), $this->s->postFieldsAdapter());
        $queries = 0;
        $count = function (string $sql) use (&$queries): string {
            $queries += str_contains($sql, $this->s->tables()->changes) ? 1 : 0;

            return $sql;
        };
        add_filter('query', $count);

        try {
            $cells = [];

            foreach ($ids as $id) {
                ob_start();
                $column->render(LastChangeColumn::COLUMN, $id);
                $cells[$id] = (string) ob_get_clean();
            }
        } finally {
            remove_filter('query', $count);
            $wp_query = $previous;
        }

        $this->assertSame(1, $queries);
        $this->assertTrue(str_contains($cells[$ids[0]], 'admin · ') && str_contains($cells[$ids[0]], ' · Title'), $cells[$ids[0]]);
        $this->assertTrue(str_contains($cells[$untouched], 'No recorded changes'));
    }

    private function truncateChangesOf(int $postId): void
    {
        $this->endRequest();
        $this->db->query($this->db->prepare("DELETE FROM {$this->s->tables()->changes} WHERE object_type = 'post' AND object_id = %d", $postId));
    }
}
