<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Tests\IntegrationTestCase;

final class RetentionTest extends IntegrationTestCase
{
    private function age(int $days): void
    {
        $t = $this->s->tables();
        $this->db->query($this->db->prepare("UPDATE {$t->changesets} SET created_at = UTC_TIMESTAMP() - INTERVAL %d DAY", $days));
        $this->db->query("UPDATE {$t->blobs} SET last_seen_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR");
    }

    public function testExpiredHistoryIsDeletedAndBlobsCollected(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Old']);
        $this->endRequest();
        $this->age(10);

        $done = $this->s->retention()->run();
        $this->assertTrue($done['expired_changesets'] >= 2);
        $this->assertCount(0, $this->changes($id));
        $this->assertSame(0, (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->s->tables()->blobs}"));
    }

    public function testRecentHistoryIsKept(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'New']);
        $this->endRequest();
        $this->age(3);
        $this->s->retention()->run();
        $this->assertCount(1, $this->changes($id, 'update'));
    }

    public function testActivePlanProtectsItsHistory(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Old']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        $this->age(10);
        $this->s->retention()->run();
        $this->assertCount(1, $this->changes($id, 'update'), 'protected by a ready plan');

        $job = $this->start($planId);
        $this->assertSame('completed', $job['status']);
        $this->assertSame('Контакты', get_post($id)->post_title, 'blobs of the plan survived GC');
    }

    public function testQuotaPausesCaptureWhenNothingCanBeFreedAndResumes(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_content' => str_repeat('x', 30000)]);
        $this->endRequest();
        $this->db->query("UPDATE {$this->s->tables()->changesets} SET is_pinned = 1");
        $this->forceQuotaBytes(1);

        try {
            $this->s->retention()->enforceQuota();
            $this->assertSame('quota_exceeded', $this->s->captureState()->pausedReason());
            wp_update_post(['ID' => $id, 'post_title' => 'Not recorded while paused']);
            $this->endRequest();
            $titles = array_filter($this->changes($id, 'update'), static fn (array $r): bool => $r['field_key'] === 'post_title');
            $this->assertCount(0, $titles);
            $this->assertCount(1, (array) $this->db->get_results("SELECT id FROM {$this->s->tables()->gaps} WHERE reason = 'quota_exceeded' AND ended_at IS NULL"));

            $this->forceQuotaBytes(PHP_INT_MAX >> 2);
            $this->s->retention()->enforceQuota();
            $this->assertNull($this->s->captureState()->pausedReason());
            $this->assertCount(1, (array) $this->db->get_results("SELECT id FROM {$this->s->tables()->gaps} WHERE reason = 'quota_exceeded' AND ended_at IS NOT NULL"));
            $this->assertCount(1, $this->changes($id, 'update'), 'pinned history was not evicted');
        } finally {
            $this->forceQuotaBytes(null);
        }
    }

    public function testQuotaEvictionDeletesOldestFirst(): void
    {
        $id = $this->createPost();

        foreach (['A', 'B', 'C'] as $v) {
            wp_update_post(['ID' => $id, 'post_content' => str_repeat($v, 20000)]);
            $this->endRequest();
        }

        add_filter('selective_undo/blob_gc_grace_seconds', $noGrace = static fn (): int => 0);
        $this->forceQuotaBytes((int) ($this->s->retention()->logicalBytes() * 0.8));

        try {
            $this->s->retention()->enforceQuota();
        } finally {
            remove_filter('selective_undo/blob_gc_grace_seconds', $noGrace);
            $this->forceQuotaBytes(null);
        }

        $remaining = $this->changes($id);
        $this->assertTrue(count($remaining) < 4);
        $this->assertSame('update', end($remaining)['event'], 'newest change kept');
    }

    public function testPurgeRequiresPreviewTokenAndConfirmation(): void
    {
        $a = $this->createPost();
        $b = $this->createPost();
        wp_update_post(['ID' => $a, 'post_content' => 'Personal data']);
        wp_update_post(['ID' => $b, 'post_content' => 'Other']);
        $this->endRequest();

        $preview = $this->s->purge()->preview(['scope' => 'object', 'object_id' => $a], 1);
        $this->assertSame(2, $preview['changes']);
        $e = $this->assertThrows(DomainError::class, fn () => $this->s->purge()->execute($preview['token'], 'delete', 1));
        $this->assertSame('su_confirmation_required', $e->errorCode);
        $e = $this->assertThrows(DomainError::class, fn () => $this->s->purge()->execute($preview['token'] . 'x', 'DELETE', 1));
        $this->assertSame('su_invalid_token', $e->errorCode);
        $this->assertThrows(DomainError::class, fn () => $this->s->purge()->execute($preview['token'], 'DELETE', $this->userId('editor')));

        $result = $this->s->purge()->execute($preview['token'], 'DELETE', 1);
        $this->assertSame(2, $result['deleted_changes']);
        $this->assertCount(0, $this->changes($a));
        $this->assertCount(2, $this->changes($b), 'other objects are kept even in the same changeset');
        $blobs = $this->db->get_col("SELECT payload FROM {$this->s->tables()->blobs}");
        $this->assertNotContains('{"f":1,"x":1,"v":["s","Personal data"]}', $blobs, 'the value is physically removed');
    }

    public function testPurgeAllSkipsDataOfActivePlans(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'X']);
        $this->endRequest();
        $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        $preview = $this->s->purge()->preview(['scope' => 'all'], 1);
        $this->assertSame(1, $preview['protected_changesets']);
        $this->s->purge()->execute($preview['token'], 'DELETE', 1);
        $this->assertCount(1, $this->changes($id, 'update'));
    }

    public function testStaleAutosaveSessionsAreSealed(): void
    {
        $id = $this->createPost(['post_type' => 'post', 'post_status' => 'draft']);
        $this->s->requestContext()->setSource(\SelectiveUndo\Application\Capture\Source::Autosave);
        wp_update_post(['ID' => $id, 'post_content' => 'draft v2']);
        $this->endRequest();
        $this->db->query("UPDATE {$this->s->tables()->changesets} SET updated_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE kind = 'autosave'");
        $this->s->retention()->run();
        $this->assertSame('sealed', $this->db->get_var("SELECT status FROM {$this->s->tables()->changesets} WHERE kind = 'autosave'"));
    }

    private function forceQuotaBytes(?int $bytes): void
    {
        static $filter = null;

        if ($filter !== null) {
            remove_filter('selective_undo/quota_bytes', $filter);
            $filter = null;
        }

        if ($bytes !== null) {
            $filter = static fn (): int => $bytes;
            add_filter('selective_undo/quota_bytes', $filter);
        }
    }
}
