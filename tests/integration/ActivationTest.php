<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

final class ActivationTest extends IntegrationTestCase
{
    public function testSchemaIsCurrentAndAllTablesAreInnoDb(): void
    {
        $this->assertTrue($this->s->schema()->isCurrent());
        $this->assertSame([], $this->s->schema()->verify());
    }

    public function testMigrationIsIdempotent(): void
    {
        delete_option('selective_undo_schema_version');
        $this->assertSame('migrated', $this->s->schema()->migrate());
        $this->assertSame('up_to_date', $this->s->schema()->migrate());
    }

    public function testCapabilitiesAreGrantedToAdministratorsOnly(): void
    {
        $this->assertTrue(user_can(1, 'sundo_restore_changes'));
        $this->assertTrue(user_can(1, 'sundo_manage_settings'));
        $this->assertFalse(user_can($this->userId('editor'), 'sundo_view_history'));
        $this->assertFalse(user_can($this->userId('author'), 'sundo_view_history'));
    }

    public function testObjectMetaCapabilitiesFollowEditPost(): void
    {
        $postId = $this->createPost(['post_author' => $this->userId('author'), 'post_type' => 'post']);
        $editor = $this->userId('editor');
        $this->assertFalse(user_can($editor, 'sundo_view_object_history', $postId), 'editor lacks the primitive cap');

        get_role('editor')->add_cap('sundo_view_history');

        try {
            $this->assertTrue(user_can($editor, 'sundo_view_object_history', $postId));
            $this->assertFalse(user_can($editor, 'sundo_restore_object', $postId), 'no restore cap');
            $this->assertFalse(user_can($this->userId('author'), 'sundo_view_object_history', $postId));
            $this->assertFalse(user_can(1, 'sundo_restore_object', 999999, 'post'), 'deleted objects cannot be restored');
            $this->assertTrue(user_can(1, 'sundo_view_object_history', 999999, 'post'), 'admin may view history of a deleted post');
            $this->assertFalse(user_can($editor, 'sundo_view_object_history', 999999, 'unknown_type'));
        } finally {
            get_role('editor')->remove_cap('sundo_view_history');
        }
    }
}
