<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Application\Capture\Source;
use SelectiveUndo\Tests\IntegrationTestCase;

final class CaptureTest extends IntegrationTestCase
{
    public function testTitleUpdateIsJournaledWithExactValues(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Контакты OLD']);
        $this->endRequest();

        $rows = $this->changes($id, 'update');
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('post_title', $row['field_key']);
        $this->assertSame('verified', $row['quality']);
        $this->assertSame('1', (string) $row['restorable']);
        $this->assertSame('page', $row['object_subtype']);
        $this->assertSame('Контакты', $this->blobValue((int) $row['before_blob_id'], $row['before_hash']));
        $this->assertSame('Контакты OLD', $this->blobValue((int) $row['after_blob_id'], $row['after_hash']));

        $sets = $this->changesets();
        $this->assertSame('sealed', end($sets)['status']);
        $this->assertSame('edit', end($sets)['kind']);
        $this->assertSame('1', (string) end($sets)['change_count']);
    }

    public function testNoOpSaveRecordsNothing(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id]);
        $this->endRequest();
        $this->assertCount(0, $this->changes($id, 'update'));
    }

    public function testMenuOrderIsTypedInt(): void
    {
        $id = $this->createPost(['menu_order' => 3]);
        wp_update_post(['ID' => $id, 'menu_order' => 7]);
        $this->endRequest();
        $row = $this->changes($id, 'update')[0];
        $this->assertSame(3, $this->blobValue((int) $row['before_blob_id'], $row['before_hash']));
        $this->assertSame(7, $this->blobValue((int) $row['after_blob_id'], $row['after_hash']));
    }

    public function testSpecialCharactersAndLargeContentRoundTrip(): void
    {
        $content = "<!-- wp:paragraph --><p>Цена: 100\\200 😀 \"q\" 'a'</p><!-- /wp:paragraph -->\n" . str_repeat("<p>Lorem ipsum dolor sit amet</p>\n", 400);
        $id = $this->createPost();
        wp_update_post(wp_slash(['ID' => $id, 'post_content' => $content]));
        $this->endRequest();
        $row = $this->changes($id, 'update')[0];
        $this->assertSame($content, $this->blobValue((int) $row['after_blob_id'], $row['after_hash']));
        $encoding = $this->db->get_var($this->db->prepare("SELECT encoding FROM {$this->s->tables()->blobs} WHERE id = %d", (int) $row['after_blob_id']));
        $this->assertSame('c1+gz', $encoding, 'binary gzip payload survives $wpdb');
    }

    public function testOneRequestOneChangeset(): void
    {
        $a = $this->createPost();
        $b = $this->createPost();
        wp_update_post(['ID' => $a, 'post_title' => 'A2']);
        wp_update_post(['ID' => $b, 'post_title' => 'B2']);
        $this->endRequest();
        $this->assertSame($this->changes($a, 'update')[0]['changeset_id'], $this->changes($b, 'update')[0]['changeset_id']);
        $sets = array_values(array_filter($this->changesets(), static fn (array $c): bool => $c['change_count'] === '2'));
        $this->assertCount(1, $sets);
        $this->assertSame('2', (string) $sets[0]['object_count']);
    }

    public function testNestedUpdateFromEditPostKeepsJournalOrder(): void
    {
        $id = $this->createPost();
        $nested = function (int $pid) use (&$nested, $id): void {
            if ($pid !== $id) {
                return;
            }

            remove_action('edit_post', $nested);
            wp_update_post(['ID' => $pid, 'post_excerpt' => 'auto-excerpt']);
        };
        add_action('edit_post', $nested);
        wp_update_post(['ID' => $id, 'post_content' => '<p>B</p>']);
        $this->endRequest();

        $rows = $this->changes($id, 'update');
        $this->assertCount(2, $rows);
        usort($rows, static fn (array $x, array $y): int => (int) $x['sequence_no'] <=> (int) $y['sequence_no']);
        $this->assertSame('post_content', $rows[0]['field_key'], 'outer frame has the lower sequence');
        $this->assertSame('post_excerpt', $rows[1]['field_key'], 'nested frame records only its own change');
    }

    public function testNestedUpdateBeforeCleanPostCacheKeepsChainContinuous(): void
    {
        $id = $this->createPost(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'T0', 'post_content' => 'C0']);
        $hook = function ($metaId, $pid, $key) use (&$hook, $id): void {
            if ((int) $pid !== $id || $key !== 'trigger') {
                return;
            }

            remove_action('added_post_meta', $hook);
            wp_update_post(['ID' => $pid, 'post_content' => 'C-from-meta-hook']);
        };
        add_action('added_post_meta', $hook, 10, 3);
        wp_update_post(['ID' => $id, 'post_title' => 'T1', 'meta_input' => ['trigger' => '1']]);
        $this->endRequest();

        $titles = $this->changes($id, 'update');
        $titleRows = array_values(array_filter($titles, static fn (array $r): bool => $r['field_key'] === 'post_title'));
        // The nested wp_update_post() reads a stale cache and writes T0 back: the journal must show it.
        $this->assertCount(2, $titleRows);
        $this->assertSame($titleRows[0]['after_hash'], $titleRows[1]['before_hash'], 'chain is continuous');
        $this->assertSame('T0', get_post($id)->post_title);
        $this->assertCount(0, $this->gapRows('external_write_detected'));
    }

    public function testStaleObjectCacheDoesNotCorruptBefore(): void
    {
        $id = $this->createPost(['post_content' => '<p>B</p>']);
        get_post($id);
        $this->db->update($this->db->posts, ['post_title' => 'Changed by SQL'], ['ID' => $id]);
        wp_update_post(['ID' => $id, 'post_content' => '<p>C</p>']);
        $this->endRequest();

        $byField = [];

        foreach ($this->changes($id, 'update') as $row) {
            $byField[$row['field_key']] = $row;
        }

        $this->assertSame('Changed by SQL', $this->blobValue((int) $byField['post_title']['before_blob_id'], $byField['post_title']['before_hash']), 'WordPress wrote the stale title back; the journal sees it');
        $this->assertSame('<p>B</p>', $this->blobValue((int) $byField['post_content']['before_blob_id'], $byField['post_content']['before_hash']));
        $this->assertCount(1, $this->gapRows('stale_object_cache'));
    }

    public function testExternalWriteIsDetectedAsGap(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $this->db->update($this->db->posts, ['post_title' => 'Three by SQL'], ['ID' => $id]);
        wp_cache_flush();
        wp_update_post(['ID' => $id, 'post_title' => 'Four']);
        $this->endRequest();
        $this->assertCount(1, $this->gapRows('external_write_detected'));
    }

    public function testAutoDraftFirstSaveIsCreationEvent(): void
    {
        require_once ABSPATH . 'wp-admin/includes/post.php';
        $draft = get_default_post_to_edit('post', true);
        $this->endRequest();
        wp_update_post(['ID' => $draft->ID, 'post_title' => 'Real title', 'post_status' => 'draft']);
        $this->endRequest();
        $this->assertCount(0, $this->changes($draft->ID, 'update'));
        $this->assertCount(1, $this->changes($draft->ID, 'created'));
    }

    public function testInformationalEvents(): void
    {
        $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X']);
        wp_trash_post($id);
        wp_untrash_post($id);
        wp_delete_post($id, true);
        $this->endRequest();
        $events = array_map(static fn (array $r): string => $r['event'], $this->changes($id));
        $this->assertContains('created', $events);
        $this->assertContains('trashed', $events);
        $this->assertContains('untrashed', $events);
        $this->assertContains('deleted', $events);
        $this->assertCount(0, $this->changes($id, 'update'), 'status changes are not field changes');
    }

    public function testUntrackedPostTypesAndRevisionsAreIgnored(): void
    {
        $id = wp_insert_post(['post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Pattern']);
        wp_update_post(['ID' => $id, 'post_title' => 'Pattern 2']);
        $this->endRequest();
        $this->assertCount(0, $this->changes((int) $id));

        $page = $this->createPost();
        wp_save_post_revision($page);
        $this->endRequest();
        $this->assertSame(0, (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->s->tables()->changes} c JOIN {$this->db->posts} p ON p.ID = c.object_id WHERE p.post_type = 'revision'"));
    }

    public function testCaptureDisabledRecordsNothing(): void
    {
        $id = $this->createPost();
        $this->s->settings()->update(['capture_enabled' => false]);
        wp_update_post(['ID' => $id, 'post_title' => 'Hidden']);
        $this->endRequest();
        $this->assertCount(0, $this->changes($id, 'update'));
    }

    public function testOversizedValueIsJournaledWithoutPayload(): void
    {
        $this->s->settings()->update(['max_value_kb' => 16]);
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_content' => str_repeat('x', 40000)]);
        $this->endRequest();
        $row = $this->changes($id, 'update')[0];
        $this->assertSame('0', (string) $row['restorable']);
        $this->assertSame('payload_not_stored', $row['reason_code']);
        $this->assertNotNull($row['after_hash']);
        $this->assertCount(1, $this->gapRows('payload_not_stored'));
    }

    public function testDeduplicatesIdenticalValues(): void
    {
        $a = $this->createPost(['post_title' => 'Same']);
        $b = $this->createPost(['post_title' => 'Same']);
        wp_update_post(['ID' => $a, 'post_title' => 'Other']);
        wp_update_post(['ID' => $b, 'post_title' => 'Other']);
        $this->endRequest();
        $this->assertSame($this->changes($a, 'update')[0]['before_blob_id'], $this->changes($b, 'update')[0]['before_blob_id']);
    }

    public function testAutosavesAreCoalescedIntoOneSession(): void
    {
        $id = $this->createPost(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Draft', 'post_content' => 'v0']);

        foreach (['v1', 'v2', 'v3'] as $version) {
            $this->s->requestContext()->setSource(Source::Autosave);
            wp_update_post(['ID' => $id, 'post_content' => $version]);
            $this->endRequest();
        }

        $rows = $this->changes($id, 'update');
        $this->assertCount(1, $rows, 'three autosaves → one change');
        $this->assertSame('v0', $this->blobValue((int) $rows[0]['before_blob_id'], $rows[0]['before_hash']));
        $this->assertSame('v3', $this->blobValue((int) $rows[0]['after_blob_id'], $rows[0]['after_hash']));
        $sets = $this->changesets();
        $this->assertSame('autosave', end($sets)['kind']);
        $this->assertSame('open', end($sets)['status']);

        // Returning to the original value inside the session leaves no change at all.
        $this->s->requestContext()->setSource(Source::Autosave);
        wp_update_post(['ID' => $id, 'post_content' => 'v0']);
        $this->endRequest();
        $this->assertCount(0, $this->changes($id, 'update'));

        // A regular save ends the session.
        $this->s->requestContext()->setSource(Source::Autosave);
        wp_update_post(['ID' => $id, 'post_content' => 'v5']);
        $this->endRequest();
        wp_update_post(['ID' => $id, 'post_title' => 'Draft 2']);
        $this->endRequest();
        $sets = $this->changesets();
        $autosave = array_values(array_filter($sets, static fn (array $c): bool => $c['kind'] === 'autosave' && (int) $c['change_count'] > 0));
        $this->assertCount(1, $autosave);
        $this->assertSame('sealed', $autosave[0]['status']);
    }

    public function testOperationApiGroupsAcrossRequests(): void
    {
        $a = $this->createPost();
        $b = $this->createPost();
        $ops = selective_undo()->operations();
        $token = $ops->begin('CSV title import', ['source_file_rows' => 2], 'partner_importer');
        wp_update_post(['ID' => $a, 'post_title' => 'Imported A']);
        $this->endRequest();

        $resumed = $ops->resume($token->id);
        wp_update_post(['ID' => $b, 'post_title' => 'Imported B']);
        $ops->finish($resumed);
        $this->endRequest();

        $op = $this->db->get_row("SELECT * FROM {$this->s->tables()->operations}", ARRAY_A);
        $this->assertSame('finished', $op['status']);
        $this->assertSame('CSV title import', $op['label']);
        $sets = array_values(array_filter($this->changesets(), static fn (array $c): bool => $c['kind'] === 'operation'));
        $this->assertCount(2, $sets);
        $this->assertSame($op['id'], $sets[0]['operation_id']);
        $this->assertSame($op['id'], $sets[1]['operation_id']);
    }

    public function testCaptureFailureNeverBreaksTheSave(): void
    {
        $id = $this->createPost();
        $this->db->query("RENAME TABLE {$this->s->tables()->changes} TO {$this->s->tables()->changes}_tmp");

        try {
            $result = wp_update_post(['ID' => $id, 'post_title' => 'Saved anyway'], true);
            $this->endRequest();
        } finally {
            $this->db->query("RENAME TABLE {$this->s->tables()->changes}_tmp TO {$this->s->tables()->changes}");
        }

        $this->assertSame($id, $result);
        $this->assertSame('Saved anyway', get_post($id)->post_title);
        $this->assertTrue(count($this->gapRows('capture_failed')) >= 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gapRows(string $reason): array
    {
        return (array) $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->s->tables()->gaps} WHERE reason = %s",
            $reason
        ), ARRAY_A);
    }
}
