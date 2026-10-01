<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Application\Capture\RevisionImporter;
use SelectiveUndo\Infrastructure\Settings\Settings;
use SelectiveUndo\Tests\IntegrationTestCase;

final class RevisionImportTest extends IntegrationTestCase
{
    private mixed $since = false;

    /** @var list<int> */
    private array $created = [];

    private const TYPE = 'su_import_test';

    public function setUp(): void
    {
        parent::setUp();
        $this->since = get_option(RevisionImporter::SINCE_OPTION);
        // Only this test's items are candidates, however much else the test site holds.
        register_post_type(self::TYPE, ['public' => true, 'capability_type' => 'page', 'map_meta_cap' => true, 'supports' => ['title', 'editor', 'excerpt', 'revisions']]);
        add_filter('selective_undo/tracked_post_types', [$this, 'trackOnlyTestType']);
    }

    /**
     * @return list<string>
     */
    public function trackOnlyTestType(): array
    {
        return [self::TYPE];
    }

    protected function createPost(array $args = []): int
    {
        return parent::createPost(['post_type' => self::TYPE] + $args);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        remove_filter('selective_undo/tracked_post_types', [$this, 'trackOnlyTestType']);

        // Their shifted revisions would be imported again by the next test.
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }

        $this->endRequest();
        $this->setImport(true);
        update_option(RevisionImporter::SINCE_OPTION, $this->since);
        update_option(RevisionImporter::STATUS_OPTION, 'done');
    }

    public function testRevisionsSavedBeforeRecordingBecomeUndoableHistory(): void
    {
        $id = $this->editBeforeRecording([
            ['post_title' => 'Контакты v2'],
            ['post_content' => '<p>Changed body</p>', 'post_excerpt' => 'Short'],
        ]);

        $result = $this->s->revisionImporter()->run(10.0);
        $this->assertTrue($result['done']);
        $this->assertSame(1, $result['items']);
        $this->assertSame(3, $result['changes']);
        $this->assertSame('imported', $this->marker($id));

        $rows = $this->changes($id, 'update');
        $this->assertSame(['post_title', 'post_content', 'post_excerpt'], array_column($rows, 'field_key'));
        $this->assertSame('Контакты', $this->blobValue((int) $rows[0]['before_blob_id'], $rows[0]['before_hash']));
        $this->assertSame('Контакты v2', $this->blobValue((int) $rows[0]['after_blob_id'], $rows[0]['after_hash']));

        $set = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->s->tables()->changesets} WHERE id = %d",
            (int) $rows[0]['changeset_id']
        ), ARRAY_A);
        $this->assertSame('revisions', $set['source']);
        $this->assertSame('edit', $set['kind']);
        $this->assertSame('sealed', $set['status']);
        $this->assertSame('1', (string) $set['actor_user_id']);
        $revisionDate = $this->db->get_var($this->db->prepare(
            "SELECT post_date_gmt FROM {$this->db->posts} WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID DESC LIMIT 1",
            $id
        ));
        $this->assertSame($revisionDate, $this->db->get_var($this->db->prepare(
            "SELECT created_at FROM {$this->s->tables()->changes} WHERE id = %d",
            (int) $rows[2]['id']
        )));

        // Undo the imported title change only; the later content change stays.
        $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => [(int) $rows[0]['id']]]));
        $this->assertSame('completed', $job['status']);
        $post = get_post($id);
        $this->assertSame('Контакты', $post->post_title);
        $this->assertSame('<p>Changed body</p>', $post->post_content);

        // The restore is recorded after the imported history.
        $restore = $this->changes($id, 'restore');
        $this->assertCount(1, $restore);
        $this->assertTrue((int) $restore[0]['id'] > (int) $rows[2]['id']);
    }

    public function testFirstRecordedChangeImportsRevisionsFirst(): void
    {
        $id = $this->editBeforeRecording([['post_title' => 'Контакты v2']]);

        wp_update_post(['ID' => $id, 'post_title' => 'Контакты v3']);
        $this->endRequest();

        $rows = $this->changes($id, 'update');
        $this->assertCount(2, $rows);
        $sources = $this->db->get_col($this->db->prepare(
            "SELECT cs.source FROM {$this->s->tables()->changes} c JOIN {$this->s->tables()->changesets} cs ON cs.id = c.changeset_id
             WHERE c.object_id = %d ORDER BY c.object_seq",
            $id
        ));
        $this->assertSame('revisions', $sources[0]);
        $this->assertNotSame('revisions', $sources[1]);
        // One continuous chain: both title changes can be undone together.
        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => array_map('intval', array_column($rows, 'id'))]));
        $this->assertSame('ready', $plan['items'][0]['status']);
        $this->assertSame(2, count($plan['items'][0]['change_ids']));
    }

    public function testRevisionsImportedLaterGoBeforeTheRecordedHistory(): void
    {
        $this->setImport(false);
        $id = $this->editBeforeRecording([['post_title' => 'Контакты v2']]);
        wp_update_post(['ID' => $id, 'post_title' => 'Контакты v3']);
        $this->endRequest();
        $this->setImport(true);

        $this->assertSame(1, $this->s->revisionImporter()->run(10.0)['changes']);

        // History order, not row order: the imported change has the larger id but comes first.
        $rows = $this->changes($id, 'update');
        $this->assertSame(['Контакты v2', 'Контакты v3'], array_map(fn (array $r): mixed => $this->blobValue((int) $r['after_blob_id'], $r['after_hash']), $rows));
        $this->assertTrue((int) $rows[0]['id'] > (int) $rows[1]['id']);

        $this->start($this->plan(['type' => 'changes', 'change_ids' => array_map('intval', array_column($rows, 'id'))]));
        $this->assertSame('Контакты', get_post($id)->post_title);
    }

    /**
     * Creates a page and edits it while recording is off, then pretends recording
     * started right after, so that the WordPress revisions predate it.
     *
     * @param list<array<string, string>> $edits
     */
    private function editBeforeRecording(array $edits): int
    {
        $this->setCapture(false);
        $id = $this->createPost();
        $this->created[] = $id;
        // Like the first save in the editor: WordPress stores the initial revision.
        wp_update_post(['ID' => $id]);
        $this->endRequest();

        foreach ($edits as $edit) {
            wp_update_post(array_merge(['ID' => $id], $edit));
            $this->endRequest();
        }

        $this->setCapture(true);
        $this->pretendRecordingStartedAfter($id);

        return $id;
    }

    /**
     * Moves the item's revisions five days back and starts recording right after them.
     */
    private function pretendRecordingStartedAfter(int $id): void
    {
        $shift = 5 * DAY_IN_SECONDS;
        $this->db->query($this->db->prepare(
            "UPDATE {$this->db->posts} SET post_date_gmt = DATE_SUB(post_date_gmt, INTERVAL %d SECOND), post_date = DATE_SUB(post_date, INTERVAL %d SECOND)
             WHERE post_parent = %d AND post_type = 'revision'",
            $shift,
            $shift,
            $id
        ));
        clean_post_cache($id);
        update_option(RevisionImporter::SINCE_OPTION, gmdate('Y-m-d H:i:s', time() - $shift + 60));
        update_option(RevisionImporter::STATUS_OPTION, 'pending');
    }

    private function setCapture(bool $enabled): void
    {
        $this->setSetting('capture_enabled', $enabled);
    }

    private function setImport(bool $enabled): void
    {
        $this->setSetting('import_revisions', $enabled);
    }

    private function setSetting(string $key, bool $value): void
    {
        $settings = get_option(Settings::OPTION);
        $settings[$key] = $value;
        update_option(Settings::OPTION, $settings);
        $this->s->settings()->flush();
    }

    private function marker(int $postId): ?string
    {
        $status = $this->db->get_var($this->db->prepare(
            "SELECT status FROM {$this->s->tables()->revisionImports} WHERE object_type = 'post' AND object_id = %d",
            $postId
        ));

        return $status === null ? null : (string) $status;
    }
}
