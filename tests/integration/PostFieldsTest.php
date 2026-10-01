<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

final class PostFieldsTest extends IntegrationTestCase
{
    public function testSlugUndoKeepsOldLinksWorkingAndRefusesATakenSlug(): void
    {
        $slug = 'about-' . strtolower(wp_generate_password(6, false, false));
        $id = $this->createPost(['post_name' => $slug]);
        wp_update_post(['ID' => $id, 'post_name' => $slug . '-new']);
        $this->endRequest();
        $change = $this->lastUpdate($id, 'post_name');

        $this->start($this->plan(['type' => 'changes', 'change_ids' => [$change]]));
        $this->assertSame($slug, get_post($id)->post_name);
        $old = get_post_meta($id, '_wp_old_slug');
        $this->assertContains($slug . '-new', $old, 'the address used meanwhile redirects');
        $this->assertNotContains($slug, $old);

        wp_update_post(['ID' => $id, 'post_name' => $slug . '-2']);
        $this->endRequest();
        $this->createPost(['post_name' => $slug]);
        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => [$this->lastUpdate($id, 'post_name')]]));
        $this->assertSame('blocked', $plan['items'][0]['status']);
        $this->assertSame('slug_taken', $plan['items'][0]['reason_code']);
    }

    public function testStatusAndDateAreUndoneButTheTrashIsNot(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_status' => 'draft', 'post_date' => '2020-01-02 03:04:05', 'edit_date' => true]);
        $this->endRequest();
        wp_trash_post($id);
        wp_untrash_post($id);
        $this->endRequest();

        $fields = array_column($this->changes($id, 'update'), 'field_key');
        sort($fields);
        $this->assertSame(['post_date', 'post_status'], $fields, 'moving to the trash is an event, not a status change');

        $this->start($this->plan(['type' => 'changes', 'change_ids' => array_map('intval', array_column($this->changes($id, 'update'), 'id'))]));
        $post = get_post($id);
        $this->assertSame('publish', $post->post_status);
        $this->assertNotSame('2020-01-02 03:04:05', $post->post_date);
        $this->assertSame(get_gmt_from_date($post->post_date), $post->post_date_gmt, 'local and UTC time stay together');
    }

    public function testParentThatWouldCreateALoopIsRefused(): void
    {
        $x = $this->createPost(['post_title' => 'X']);
        $y = $this->createPost(['post_title' => 'Y']);
        $page = $this->createPost(['post_title' => 'Page', 'post_parent' => $x]);
        wp_update_post(['ID' => $page, 'post_parent' => $y]);
        $this->endRequest();
        $change = $this->lastUpdate($page, 'post_parent');
        wp_update_post(['ID' => $x, 'post_parent' => $page]);
        $this->endRequest();

        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => [$change]]));
        $this->assertSame('blocked', $plan['items'][0]['status']);
        $this->assertSame('parent_invalid', $plan['items'][0]['reason_code']);
    }

    private function lastUpdate(int $postId, string $field): int
    {
        $ids = array_column(array_filter($this->changes($postId, 'update'), static fn (array $r): bool => $r['field_key'] === $field), 'id');

        return (int) end($ids);
    }
}
