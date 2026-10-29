<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Infrastructure\WordPress\PostDeletionAdapter;
use SelectiveUndo\Tests\IntegrationTestCase;

final class DeletionTest extends IntegrationTestCase
{
    public function testPermanentlyDeletedPostComesBackAsADraftWithItsFieldsAndTerms(): void
    {
        [$tag, $slug] = ['tag-' . wp_generate_password(6, false), 'price-list-' . wp_generate_password(6, false, false)];
        $id = $this->createPost(['post_type' => 'post', 'post_title' => 'Price list', 'post_content' => '<p>Pumps</p>', 'post_name' => $slug, 'tags_input' => [$tag]]);
        add_post_meta($id, 'color', 'blue');
        add_post_meta($id, 'color', 'green'); // several rows of one key, in order
        add_post_meta($id, '_pingme', '1'); // as WordPress leaves it on a published post
        $this->endRequest();

        // As in the admin: to the trash first (status and slug change), then deleted from there.
        wp_trash_post($id);
        $this->endRequest();
        wp_delete_post($id, true);
        $this->endRequest();
        $this->assertNull(get_post($id));
        $this->assertSame(0, (int) get_term_by('name', $tag, 'post_tag')->count);

        $deleted = $this->changes($id, 'deleted');
        $this->assertCount(1, $deleted, 'one entry for the deletion');
        $this->assertSame(PostDeletionAdapter::FIELD, $deleted[0]['field_key']);
        $this->assertSame('1', (string) $deleted[0]['restorable']);

        $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => [(int) $deleted[0]['id']]]));
        $this->assertSame('completed', $job['status']);

        $post = get_post($id);
        $this->assertInstanceOf(\WP_Post::class, $post, 'the same ID');
        $this->assertSame(['Price list', '<p>Pumps</p>', strtolower($slug), 'draft'], [$post->post_title, $post->post_content, $post->post_name, $post->post_status]);
        $this->assertSame('publish', get_post_meta($id, PostDeletionAdapter::STATUS_META, true), 'the status it had is kept');
        $this->assertSame(['blue', 'green'], get_post_meta($id, 'color'));
        $this->assertSame('', get_post_meta($id, '_pingme', true), 'no pingbacks are sent again');
        $this->assertSame([$tag], wp_get_post_tags($id, ['fields' => 'names']));
        $this->assertCount(1, $this->changes($id, 'restore'));

        // A second restore of the same deletion finds the item back already.
        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => [(int) $deleted[0]['id']]]));
        $this->assertSame('already_restored', $plan['items'][0]['status']);

        // Undoing that restore would delete the post again: refused (the trash is the way).
        $restore = $this->changes($id, 'restore')[0];
        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => [(int) $restore['id']]]));
        $this->assertSame('deletion_not_restorable', $plan['items'][0]['reason_code']);
    }

    public function testRestoreIsRefusedWhenTheIdWasTakenAndMediaAreNotCovered(): void
    {
        $id = $this->createPost(['post_type' => 'post', 'post_title' => 'Gone']);
        wp_delete_post($id, true);
        $this->endRequest();
        $change = (int) $this->changes($id, 'deleted')[0]['id'];

        // Something else was created with this ID meanwhile (an import keeping IDs).
        wp_insert_post(['import_id' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Other']);
        $this->endRequest();
        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => [$change]]));
        $this->assertSame('object_id_taken', $plan['items'][0]['reason_code']);
        $this->assertSame('Other', get_post($id)->post_title);

        $media = wp_insert_attachment(['post_title' => 'photo.jpg', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], 'photo.jpg');
        $this->endRequest();
        wp_delete_attachment($media, true);
        $this->endRequest();
        $this->assertSame('', $this->changes($media, 'deleted')[0]['field_key'] ?? '', 'media: the deletion is only noted');
    }
}
