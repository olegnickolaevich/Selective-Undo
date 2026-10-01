<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Domain\Contracts\FieldDefinition;
use SelectiveUndo\Tests\IntegrationTestCase;

final class AdaptersTest extends IntegrationTestCase
{
    public function tearDown(): void
    {
        parent::tearDown();
        remove_all_filters('selective_undo/meta_fields');
        $this->s->metaFields()->flush();
    }

    public function testTitleImageAndTagsOfOneSaveAreUndoneTogether(): void
    {
        $image = $this->attachment('old.jpg');
        $newImage = $this->attachment('new.jpg');
        [$red, $blue] = ['red-' . wp_generate_password(6, false), 'blue-' . wp_generate_password(6, false)];
        $id = $this->createPost(['post_type' => 'post', 'tags_input' => [$red]]);
        set_post_thumbnail($id, $image);
        $this->endRequest();
        $this->truncateJournal();

        wp_update_post(['ID' => $id, 'post_title' => 'New title']);
        set_post_thumbnail($id, $newImage);
        wp_set_post_tags($id, [$blue]);
        $this->endRequest();

        $sets = $this->changesets();
        $this->assertCount(1, $sets, 'one save, one change set');
        $fields = array_column($this->changes($id, 'update'), 'field_key');
        sort($fields);
        $this->assertSame(['meta:_thumbnail_id', 'post_title', 'tax:post_tag'], $fields);

        $diff = $this->s->diffs()->change($this->changeOf($id, 'tax:post_tag'), 1, 0);
        $this->assertSame([['op' => 'delete', 'text' => $red], ['op' => 'insert', 'text' => $blue]], $diff['lines'], 'term names, not IDs');

        $job = $this->start($this->plan(['type' => 'changeset', 'changeset_id' => (string) $sets[0]['uuid']]));
        $this->assertSame('completed', $job['status']);
        $this->assertSame(3, $job['counters']['restored']);
        $this->assertSame('Контакты', get_post($id)->post_title);
        $this->assertSame($image, (int) get_post_thumbnail_id($id));
        $this->assertSame([$red], wp_get_post_tags($id, ['fields' => 'names']));
        $this->assertSame(0, (int) get_term_by('name', $blue, 'post_tag')->count, 'term counts follow the restore');
        $this->assertSame(1, (int) get_term_by('name', $red, 'post_tag')->count);
        $this->assertCount(3, $this->changes($id, 'restore'));
    }

    public function testUndoOfATagChangeKeepsTagsChangedLater(): void
    {
        $id = $this->createPost(['post_type' => 'post', 'tags_input' => ['a']]);
        wp_set_post_tags($id, ['a', 'b']);
        $this->endRequest();
        $addB = $this->changeOf($id, 'tax:post_tag');
        wp_set_post_tags($id, ['c'], true);
        $this->endRequest();

        $this->start($this->plan(['type' => 'changes', 'change_ids' => [$addB]]));
        $names = wp_get_post_tags($id, ['fields' => 'names']);
        sort($names);
        $this->assertSame(['a', 'c'], $names);
    }

    public function testOnlyRegisteredMetaKeysAreRecorded(): void
    {
        add_filter('selective_undo/meta_fields', static fn (array $fields): array => [
            ...$fields,
            new FieldDefinition('meta:price', 'Price', 'text'),
        ]);
        $this->s->metaFields()->flush();
        $id = $this->createPost(['post_type' => 'post']);

        update_post_meta($id, 'price', '10');
        update_post_meta($id, 'views', '999');
        $this->endRequest();
        update_post_meta($id, 'price', '12');
        $this->endRequest();

        $changes = $this->changes($id, 'update');
        $this->assertSame(['meta:price', 'meta:price'], array_column($changes, 'field_key'));

        // Undo of both: the key did not exist before, so it is removed again.
        $this->start($this->plan(['type' => 'changes', 'change_ids' => array_map('intval', array_column($changes, 'id'))]));
        $this->assertSame([], get_post_meta($id, 'price'));
        $this->assertSame('999', get_post_meta($id, 'views', true));
    }

    public function testMediaCaptionAndAltTextAreUndone(): void
    {
        $id = $this->attachment('photo.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', 'A cat');
        $this->endRequest();
        $this->truncateJournal();

        wp_update_post(['ID' => $id, 'post_excerpt' => 'New caption']);
        update_post_meta($id, '_wp_attachment_image_alt', 'A dog');
        $this->endRequest();

        $job = $this->start($this->plan(['type' => 'changeset', 'changeset_id' => (string) $this->changesets()[0]['uuid']]));
        $this->assertSame('completed', $job['status']);
        $this->assertSame('', get_post($id)->post_excerpt);
        $this->assertSame('A cat', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    private function attachment(string $file): int
    {
        $id = wp_insert_attachment(['post_title' => $file, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], $file);
        $this->endRequest();

        return (int) $id;
    }

    private function changeOf(int $postId, string $field): int
    {
        $ids = array_column(array_filter($this->changes($postId, 'update'), static fn (array $r): bool => $r['field_key'] === $field), 'id');

        return (int) end($ids);
    }
}
