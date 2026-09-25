<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Tests\IntegrationTestCase;

/**
 * Critical restore scenarios from the specification (section 36.2).
 */
final class RestoreTest extends IntegrationTestCase
{
    public function testTitleUndoRestoresOnlyTheTitle(): void
    {
        $id = $this->createPost(['post_title' => 'Контакты', 'post_content' => '<p>Body</p>', 'post_name' => 'kontakty']);
        wp_update_post(['ID' => $id, 'post_title' => 'Контакты OLD']);
        $this->endRequest();
        $before = get_post($id);

        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]));
        $this->assertSame('ready', $plan['status']);
        $this->assertSame(1, $plan['summary']['ready']);
        $this->assertSame('ready', $plan['items'][0]['status']);
        $this->assertSame('Title', $plan['items'][0]['field_label']);

        $job = $this->start($plan['id']);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(1, $job['counters']['restored']);

        $after = get_post($id);
        $this->assertSame('Контакты', $after->post_title);
        $this->assertSame($before->post_content, $after->post_content);
        $this->assertSame($before->post_name, $after->post_name);
        $this->assertSame($before->guid, $after->guid);
        $this->assertSame($before->post_date, $after->post_date);
        $this->assertSame($before->post_author, $after->post_author);

        $restore = $this->changes($id, 'restore');
        $this->assertCount(1, $restore);
        $this->assertSame('Контакты OLD', $this->blobValue((int) $restore[0]['before_blob_id'], $restore[0]['before_hash']));
        $this->assertSame('Контакты', $this->blobValue((int) $restore[0]['after_blob_id'], $restore[0]['after_hash']));
        $this->assertSame((string) $this->updateIds($id)[0], (string) $restore[0]['reverts_change_id']);
    }

    public function testLaterChangeOfAnotherFieldIsKept(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Imported']);
        $this->endRequest();
        $titleChange = $this->updateIds($id, 'post_title');
        wp_update_post(['ID' => $id, 'post_content' => '<p>Edited on Tuesday</p>']);
        $this->endRequest();

        $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => $titleChange]));
        $this->assertSame('completed', $job['status']);
        $this->assertSame('Контакты', get_post($id)->post_title);
        $this->assertSame('<p>Edited on Tuesday</p>', get_post($id)->post_content);
    }

    public function testFieldChangedAgainIsAConflictAndIsNotOverwritten(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Контакты OLD']);
        $this->endRequest();
        $first = $this->updateIds($id);
        wp_update_post(['ID' => $id, 'post_title' => 'Связаться с командой']);
        $this->endRequest();

        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $first]));
        $this->assertSame('conflict', $plan['items'][0]['status']);
        $this->assertSame('current_value_changed', $plan['items'][0]['reason_code']);
        $this->assertSame(0, $plan['summary']['ready']);
        $e = $this->assertThrows(DomainError::class, fn () => $this->start($plan['id']));
        $this->assertSame('su_nothing_to_restore', $e->errorCode);
        $this->assertSame('Связаться с командой', get_post($id)->post_title);
    }

    public function testConflictAppearingAfterPreviewIsDetectedBeforeWrite(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        wp_update_post(['ID' => $id, 'post_title' => 'Edited between preview and restore']);
        $this->endRequest();

        $job = $this->start($planId);
        $this->assertSame('completed_with_conflicts', $job['status']);
        $this->assertSame(1, $job['counters']['conflict']);
        $this->assertSame('Edited between preview and restore', get_post($id)->post_title);
    }

    public function testChainOfChangesFoldsToTheFirstBefore(): void
    {
        $id = $this->createPost(['post_title' => 'X']);
        wp_update_post(['ID' => $id, 'post_title' => 'Y']);
        $this->endRequest();
        wp_update_post(['ID' => $id, 'post_title' => 'Z']);
        $this->endRequest();

        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]));
        $this->assertCount(1, $plan['items']);
        $this->assertCount(2, $plan['items'][0]['change_ids']);
        $this->start($plan['id']);
        $this->assertSame('X', get_post($id)->post_title);
    }

    public function testBrokenChainIsBlocked(): void
    {
        $id = $this->createPost(['post_title' => 'X']);
        wp_update_post(['ID' => $id, 'post_title' => 'Y']);
        $this->endRequest();
        wp_update_post(['ID' => $id, 'post_title' => 'Z']);
        $this->endRequest();
        wp_update_post(['ID' => $id, 'post_title' => 'W']);
        $this->endRequest();
        $ids = $this->updateIds($id);

        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => [$ids[0], $ids[2]]]));
        $this->assertSame('blocked', $plan['items'][0]['status']);
        $this->assertSame('chain_broken', $plan['items'][0]['reason_code']);
    }

    public function testAbaWarningWhenFieldChangedAndCameBack(): void
    {
        $id = $this->createPost(['post_title' => 'A']);
        wp_update_post(['ID' => $id, 'post_title' => 'B']);
        $this->endRequest();
        $first = $this->updateIds($id);
        wp_update_post(['ID' => $id, 'post_title' => 'C']);
        $this->endRequest();
        wp_update_post(['ID' => $id, 'post_title' => 'B']);
        $this->endRequest();

        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $first]));
        $this->assertSame('ready', $plan['items'][0]['status']);
        $this->assertContains('later_changes_exist', $plan['items'][0]['warnings']);
    }

    public function testMissingObjectIsReportedClearly(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $changes = $this->updateIds($id);
        wp_delete_post($id, true);
        $this->endRequest();

        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $changes]));
        $this->assertSame('missing_object', $plan['items'][0]['status']);
        $this->assertFalse($plan['items'][0]['object']['exists']);
    }

    public function testTrashedObjectIsBlocked(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $changes = $this->updateIds($id);
        wp_trash_post($id);
        $this->endRequest();

        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $changes]));
        $this->assertSame('blocked', $plan['items'][0]['status']);
        $this->assertSame('object_trashed', $plan['items'][0]['reason_code']);
    }

    public function testUserWithoutPermissionCannotPreview(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $e = $this->assertThrows(DomainError::class, fn () => $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)], $this->userId('editor')));
        $this->assertSame('su_forbidden', $e->errorCode);
    }

    public function testLostPermissionBetweenPreviewAndRestoreSkipsTheObject(): void
    {
        $role = get_role('editor');
        $role->add_cap('sundo_view_history');
        $role->add_cap('sundo_restore_changes');
        $editor = $this->userId('editor');

        try {
            $id = $this->createPost();
            wp_update_post(['ID' => $id, 'post_title' => 'Two']);
            $this->endRequest();
            $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)], $editor);
            $role->remove_cap('sundo_restore_changes');
            wp_cache_flush();
            $job = $this->start($planId, null, $editor);
            $this->assertSame('completed_with_conflicts', $job['status']);
            $this->assertSame(1, $job['counters']['skipped']);
            $this->assertSame('Two', get_post($id)->post_title);
        } finally {
            $role->remove_cap('sundo_view_history');
            $role->remove_cap('sundo_restore_changes');
        }
    }

    public function testUnfilteredHtmlIsRequiredToRestoreScripts(): void
    {
        $role = get_role('author');
        $role->add_cap('sundo_view_history');
        $role->add_cap('sundo_restore_changes');
        $author = $this->userId('author');

        try {
            $id = $this->createPost(['post_type' => 'post', 'post_author' => $author, 'post_content' => '<p>Safe</p><script>alert(1)</script>']);
            wp_set_current_user($author);
            wp_update_post(['ID' => $id, 'post_content' => '<p>Safe</p>']);
            $this->endRequest();
            $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)], $author), $author);
            $this->assertSame('blocked', $plan['items'][0]['status']);
            $this->assertSame('requires_unfiltered_html', $plan['items'][0]['reason_code']);

            wp_set_current_user(1);
            $adminPlan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]));
            $this->assertSame('ready', $adminPlan['items'][0]['status'], 'administrators have unfiltered_html');
        } finally {
            $role->remove_cap('sundo_view_history');
            $role->remove_cap('sundo_restore_changes');
            wp_set_current_user(1);
        }
    }

    public function testExpiredPlanRequiresNewPreview(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        $this->db->query($this->db->prepare("UPDATE {$this->s->tables()->plans} SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE uuid = %s", $planId));
        $this->assertSame('expired', $this->planView($planId)['status']);
        $e = $this->assertThrows(DomainError::class, fn () => $this->start($planId));
        $this->assertSame('su_plan_expired', $e->errorCode);
        $this->assertSame('Two', get_post($id)->post_title);
    }

    public function testSameRequestTwiceRunsOnce(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        $key = wp_generate_uuid4();

        $first = $this->start($planId, $key);
        $second = $this->start($planId, $key);
        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertCount(1, $this->changes($id, 'restore'));

        $e = $this->assertThrows(DomainError::class, fn () => $this->start($planId, wp_generate_uuid4()));
        $this->assertSame('su_plan_consumed', $e->errorCode);
        $this->assertSame($first['id'], $e->details['job_id']);

        $other = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        $e = $this->assertThrows(DomainError::class, fn () => $this->start($other, $key));
        $this->assertSame('su_idempotency_key_reused', $e->errorCode);
    }

    public function testFailureBeforeCommitChangesNothingAndRetryAppliesOnce(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        $changes = $this->s->tables()->changes;
        // The journal insert fails after the content UPDATE inside the transaction.
        $this->db->query("ALTER TABLE {$changes} ADD CONSTRAINT sundo_test_block CHECK (event <> 'restore')");

        try {
            $job = $this->start($planId);
        } finally {
            $this->db->query("ALTER TABLE {$changes} DROP CHECK sundo_test_block");
        }

        $this->assertSame('running', $job['status']);
        $this->assertSame(1, $job['counters']['pending']);
        $this->assertSame('Two', get_post($id)->post_title, 'content rolled back together with the journal');

        $jobRow = $this->s->jobs()->findByUuid($job['id']);
        $this->assertTrue($this->s->executeJob()->run((int) $jobRow['id']));
        $this->endRequest();
        $this->assertSame('Контакты', get_post($id)->post_title);
        $this->assertCount(1, $this->changes($id, 'restore'));

        // Running again after commit is a no-op (lost response, duplicate worker).
        $this->assertFalse($this->s->executeJob()->run((int) $jobRow['id']));
        $this->assertCount(1, $this->changes($id, 'restore'));
    }

    public function testPostProcessingFailureDoesNotUndoTheRestore(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $fail = static function (): void {
            throw new \RuntimeException('integration down');
        };
        add_action('selective_undo/object_restored', $fail);

        try {
            $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]));
        } finally {
            remove_action('selective_undo/object_restored', $fail);
        }

        $this->assertSame('completed', $job['status']);
        $this->assertSame('pending', $job['postprocess_status']);
        $this->assertSame('Контакты', get_post($id)->post_title);

        $received = [];
        $collect = static function (array $payload) use (&$received): void {
            $received[] = $payload;
        };
        add_action('selective_undo/object_restored', $collect);
        $this->db->query("UPDATE {$this->s->tables()->outbox} SET available_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND");
        $this->s->outbox()->dispatchDue();
        remove_action('selective_undo/object_restored', $collect);

        $this->assertCount(1, $received);
        $this->assertSame(['post_title'], $received[0]['fields']);
        $this->assertNotNull($received[0]['event_id']);
        $jobRow = $this->s->jobs()->findByUuid($job['id']);
        $this->s->jobs()->setPostprocessStatus((int) $jobRow['id']);
        $this->assertSame('done', $this->s->jobView()->forUser($job['id'], 1)['postprocess_status']);
    }

    public function testCorruptBlobBlocksTheRestore(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $change = $this->changes($id, 'update')[0];
        $this->db->query($this->db->prepare(
            "UPDATE {$this->s->tables()->blobs} SET payload = %s WHERE id = %d",
            '{"f":1,"x":1,"v":["s","Tampered"]}',
            (int) $change['before_blob_id']
        ));
        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]));
        $this->assertSame('blocked', $plan['items'][0]['status']);
        $this->assertTrue(str_starts_with((string) $plan['items'][0]['reason_code'], 'payload_'));
    }

    public function testUndoOfUndoRechecksConflicts(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'B']);
        $this->endRequest();
        $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]));
        $this->assertSame('Контакты', get_post($id)->post_title);

        $undo = $this->start($this->plan(['type' => 'changeset', 'changeset_id' => $job['restore_changeset_id']]));
        $this->assertSame('completed', $undo['status']);
        $this->assertSame('B', get_post($id)->post_title);

        // Undoing the first restore again: the title is already B.
        $again = $this->planView($this->plan(['type' => 'changeset', 'changeset_id' => $job['restore_changeset_id']]));
        $this->assertSame('already_restored', $again['items'][0]['status']);

        // Redo after a new edit is a conflict, never a blind replay.
        wp_update_post(['ID' => $id, 'post_title' => 'C']);
        $this->endRequest();
        $redo = $this->planView($this->plan(['type' => 'changeset', 'changeset_id' => $undo['restore_changeset_id']]));
        $this->assertSame('conflict', $redo['items'][0]['status']);
        $this->assertSame('C', get_post($id)->post_title);
    }

    public function testFreeVersionRestoresOneObjectPerPlan(): void
    {
        $a = $this->createPost();
        $b = $this->createPost();
        wp_update_post(['ID' => $a, 'post_title' => 'A2']);
        wp_update_post(['ID' => $b, 'post_title' => 'B2']);
        $this->endRequest();
        $changeset = $this->db->get_var("SELECT uuid FROM {$this->s->tables()->changesets} WHERE change_count = 2");
        $e = $this->assertThrows(DomainError::class, fn () => $this->plan(['type' => 'changeset', 'changeset_id' => $changeset]));
        $this->assertSame('su_selection_requires_pro', $e->errorCode);

        $plan = $this->planView($this->plan(['type' => 'changeset', 'changeset_id' => $changeset, 'object_ids' => [$a]]));
        $this->assertCount(1, $plan['items']);
    }

    public function testRestoreBumpsModifiedDateAndCreatesRevision(): void
    {
        $id = $this->createPost(['post_type' => 'post']);
        wp_update_post(['ID' => $id, 'post_title' => 'Two', 'post_content' => '<p>New</p>']);
        $this->endRequest();
        $this->db->update($this->db->posts, ['post_modified_gmt' => '2020-01-01 00:00:00'], ['ID' => $id]);
        clean_post_cache($id);
        $revisionsBefore = count(wp_get_post_revisions($id));
        $updatesBefore = count($this->changes($id, 'update'));

        $this->start($this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]));
        $post = get_post($id);
        $this->assertSame('Контакты', $post->post_title);
        $this->assertTrue(strtotime($post->post_modified_gmt . ' UTC') > time() - 120, 'post_modified_gmt is the restore time');
        $this->assertSame($revisionsBefore + 1, count(wp_get_post_revisions($id)));
        $this->assertCount($updatesBefore, $this->changes($id, 'update'), 'the revision is not journaled as an edit');
    }

    public function testCancelBeforeStartCancelsTheJob(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        add_filter('selective_undo/max_objects_per_plan', $max = static fn (): int => 100);

        try {
            $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
            // Force the queued path.
            $this->db->query("UPDATE {$this->s->tables()->plans} SET object_count = 999 WHERE uuid = '{$planId}'");
            $job = $this->start($planId);
        } finally {
            remove_filter('selective_undo/max_objects_per_plan', $max);
        }

        $this->assertSame('queued', $job['status']);
        $jobRow = $this->s->jobs()->findByUuid($job['id']);
        $this->assertTrue($this->s->jobs()->requestCancel((int) $jobRow['id']));
        $this->assertTrue($this->s->executeJob()->run((int) $jobRow['id']));
        $this->assertSame('cancelled', $this->s->jobView()->forUser($job['id'], 1)['status']);
        $this->assertSame('Two', get_post($id)->post_title);
    }

    public function testWrongDatabaseBlocksRestore(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        $original = get_option('selective_undo_instance_id');
        $this->s->restoreConnection()->close();
        // WordPress sees a different marker than the database the dedicated connection reaches.
        add_filter('pre_option_selective_undo_instance_id', $fake = static fn (): string => 'not-the-same');

        try {
            $e = $this->assertThrows(DomainError::class, fn () => $this->start($planId));
            $this->assertSame('su_restore_unavailable', $e->errorCode);
            $this->assertContains('restore_connection_wrong_database', $e->details['blockers']);
        } finally {
            remove_filter('pre_option_selective_undo_instance_id', $fake);
            delete_transient('selective_undo_restore_ready');
            $this->assertSame($original, get_option('selective_undo_instance_id'));
        }
    }
}
