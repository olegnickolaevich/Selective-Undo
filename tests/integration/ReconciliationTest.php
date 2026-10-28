<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Application\Capture\ReconciliationScanner;
use SelectiveUndo\Tests\IntegrationTestCase;

final class ReconciliationTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        delete_option(ReconciliationScanner::CURSOR_OPTION);
        delete_option('selective_undo_site_moved_reason');
    }

    public function testChangeMadeWithSqlIsRecordedAndCanBeUndone(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Contacts']);
        $this->endRequest();

        // As `wp search-replace` does: straight to the database, no WordPress hooks.
        $this->db->query($this->db->prepare("UPDATE {$this->db->posts} SET post_title = %s WHERE ID = %d", 'Contacts (replaced)', $id));
        clean_post_cache($id);
        $result = $this->s->reconciliation()->run(10.0);
        $this->endRequest();

        $this->assertSame(1, $result['drifted']);
        $changes = $this->changes($id, 'update');
        $last = end($changes);
        $this->assertSame('post_title', $last['field_key']);
        $this->assertSame('reconciled', $last['quality']);
        $changeset = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->s->tables()->changesets} WHERE id = %d", (int) $last['changeset_id']), ARRAY_A);
        $this->assertSame('external', $changeset['source']);

        $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => [(int) $last['id']]]));
        $this->assertSame('completed', $job['status']);
        $this->assertSame('Contacts', get_post($id)->post_title);

        // An item in the trash differs only by what moving it there changed: no edit.
        $trashed = $this->createPost();
        wp_update_post(['ID' => $trashed, 'post_title' => 'Old page', 'post_name' => 'old-page', 'post_status' => 'publish']);
        $this->endRequest();
        wp_trash_post($trashed);
        $this->endRequest();

        // A second check finds nothing new.
        $this->s->reconciliation()->restart();
        $this->assertSame(0, $this->s->reconciliation()->run(10.0)['drifted']);
    }

    public function testRestoredBackupOpensOneGapInsteadOfHundredsOfChanges(): void
    {
        $ids = [];

        for ($i = 0; $i < 60; $i++) {
            $id = $this->createPost(['post_title' => "Page {$i}"]);
            wp_update_post(['ID' => $id, 'post_title' => "Page {$i} edited"]);
            $ids[] = $id;
        }

        $this->endRequest();
        $before = count($this->db->get_col("SELECT id FROM {$this->s->tables()->changes}"));
        // An older dump is imported: every title goes back.
        foreach ($ids as $i => $id) {
            $this->db->query($this->db->prepare("UPDATE {$this->db->posts} SET post_title = %s WHERE ID = %d", "Page {$i}", $id));
        }

        $result = $this->s->reconciliation()->run(10.0);
        $this->endRequest();

        $this->assertTrue($result['restored_backup']);
        $this->assertSame($before, count($this->db->get_col("SELECT id FROM {$this->s->tables()->changes}")));
        $this->assertSame('database_restored', $this->s->siteFingerprint()->reason());
        $this->assertSame('1', (string) $this->db->get_var("SELECT COUNT(*) FROM {$this->s->tables()->gaps} WHERE reason = 'database_restored' AND ended_at IS NULL"));

        // Nothing is recorded until the administrator decides.
        $this->s->reconciliation()->restart();
        $this->assertSame(0, $this->s->reconciliation()->run(10.0)['checked']);
        $this->s->siteFingerprint()->accept();
    }
}
