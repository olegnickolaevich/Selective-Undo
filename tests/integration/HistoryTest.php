<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Tests\IntegrationTestCase;

final class HistoryTest extends IntegrationTestCase
{
    public function testListNewestFirstWithCursorPagination(): void
    {
        $id = $this->createPost();

        for ($i = 1; $i <= 5; $i++) {
            wp_update_post(['ID' => $id, 'post_title' => 'Title ' . $i]);
            $this->endRequest();
        }

        $page1 = $this->s->history()->list(1, [], null, 2);
        $this->assertCount(2, $page1['items']);
        $this->assertNotNull($page1['next_cursor']);
        $page2 = $this->s->history()->list(1, [], $page1['next_cursor'], 2);
        $page3 = $this->s->history()->list(1, [], $page2['next_cursor'], 2);
        $all = array_merge($page1['items'], $page2['items'], $page3['items']);
        $this->assertCount(6, $all, '5 edits + 1 creation event');
        $this->assertNull($page3['next_cursor']);
        $this->assertSame([['key' => 'post_title', 'label' => 'Title']], $all[0]['fields']);
        $this->assertSame('Title 5', $all[0]['objects'][0]['title'], 'current title');
        $this->assertSame('admin', $all[0]['actor']['name']);
        $this->assertCount(6, array_unique(array_column($all, 'id')));
    }

    public function testFiltersByActorObjectSearchAndKind(): void
    {
        $a = $this->createPost(['post_title' => 'Alpha page']);
        $b = $this->createPost(['post_title' => 'Beta page']);
        wp_update_post(['ID' => $a, 'post_content' => 'A2']);
        $this->endRequest();
        wp_set_current_user($this->userId('editor'));
        wp_update_post(['ID' => $b, 'post_content' => 'B2']);
        $this->endRequest();
        wp_set_current_user(1);

        $mine = $this->s->history()->list(1, ['actor' => 1, 'kind' => 'edit'], null, 50)['items'];
        $this->assertTrue(count($mine) >= 1);

        foreach ($mine as $item) {
            $this->assertSame(1, $item['actor']['id']);
        }

        $byObject = $this->s->history()->list(1, ['object_id' => $b], null, 50)['items'];
        $this->assertCount(2, $byObject, 'creation + edit of B');

        $search = $this->s->history()->list(1, ['search' => 'Beta'], null, 50)['items'];
        $this->assertCount(2, $search);
        $this->assertSame([], $this->s->history()->list(1, ['search' => 'no such title zzz'], null, 50)['items']);
        $this->assertCount(2, $this->s->history()->list(1, ['search' => (string) $a], null, 50)['items']);
    }

    public function testObjectsTheViewerCannotEditAreHidden(): void
    {
        $role = get_role('author');
        $role->add_cap('sundo_view_history');
        $author = $this->userId('author');

        try {
            $own = $this->createPost(['post_type' => 'post', 'post_author' => $author]);
            $foreign = $this->createPost(['post_type' => 'post', 'post_author' => 1]);
            wp_update_post(['ID' => $own, 'post_title' => 'Own 2']);
            wp_update_post(['ID' => $foreign, 'post_title' => 'Foreign 2']);
            $this->endRequest();

            $items = $this->s->history()->list($author, ['kind' => 'edit'], null, 50)['items'];
            $shared = array_values(array_filter($items, static fn (array $i): bool => $i['change_count'] === 2));
            $this->assertCount(1, $shared);
            $this->assertSame(1, $shared[0]['object_count']);
            $this->assertSame(1, $shared[0]['hidden_objects']);
            $this->assertSame($own, $shared[0]['objects'][0]['id']);

            $detail = $this->s->history()->changeset($shared[0]['id'], $author);
            $this->assertCount(1, $detail['objects']);

            $this->assertThrows(DomainError::class, fn () => $this->s->history()->objectTimeline(new ObjectRef('post', $foreign), $author, null, 20));
            $e = $this->assertThrows(DomainError::class, fn () => $this->s->diffs()->change($this->updateIds($foreign)[0], $author, 0));
            $this->assertSame('su_not_found', $e->errorCode, 'does not reveal existence');
        } finally {
            $role->remove_cap('sundo_view_history');
        }
    }

    public function testChangesetDetailAndTimeline(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'T2', 'post_excerpt' => 'E2']);
        $this->endRequest();
        $item = $this->s->history()->list(1, ['kind' => 'edit'], null, 1)['items'][0];
        $detail = $this->s->history()->changeset($item['id'], 1);
        $this->assertCount(1, $detail['objects']);
        $fields = array_column($detail['objects'][0]['changes'], 'field');
        sort($fields);
        $this->assertSame(['post_excerpt', 'post_title'], $fields);
        $this->assertTrue($detail['objects'][0]['changes'][0]['restorable']);

        $timeline = $this->s->history()->objectTimeline(new ObjectRef('post', $id), 1, null, 20);
        $this->assertSame('T2', $timeline['object']['title']);
        $this->assertCount(3, $timeline['items'], 'two field changes and the creation event');
        $this->assertSame($item['id'], $timeline['items'][0]['changeset']['id']);
    }

    public function testDiffsAreTextOnlyAndCollapsed(): void
    {
        $lines = [];

        for ($i = 1; $i <= 60; $i++) {
            $lines[] = '<p>Line ' . $i . '</p>';
        }

        $id = $this->createPost(['post_content' => implode("\n", $lines)]);
        $changed = $lines;
        $changed[29] = '<p>Line 30 <script>alert(1)</script></p>';
        wp_update_post(wp_slash(['ID' => $id, 'post_content' => implode("\n", $changed)]));
        $this->endRequest();

        $diff = $this->s->diffs()->change($this->updateIds($id)[0], 1, 0);
        $this->assertSame('before_after', $diff['mode']);
        $ops = array_column($diff['lines'], 'op');
        $this->assertContains('collapsed', $ops);
        $this->assertContains('delete', $ops);
        $this->assertContains('insert', $ops);
        $inserted = array_values(array_filter($diff['lines'], static fn (array $l): bool => $l['op'] === 'insert'));
        $this->assertSame('<p>Line 30 <script>alert(1)</script></p>', $inserted[0]['text'], 'raw text, rendering is the UI\'s job');
        $this->assertTrue(count($diff['lines']) < 15);
    }

    public function testPlanItemDiffsCurrentTargetAndConflict(): void
    {
        $id = $this->createPost(['post_title' => 'Before']);
        wp_update_post(['ID' => $id, 'post_title' => 'After']);
        $this->endRequest();
        $first = $this->updateIds($id);
        wp_update_post(['ID' => $id, 'post_title' => 'Now']);
        $this->endRequest();
        $plan = $this->planView($this->plan(['type' => 'changes', 'change_ids' => $first]));
        $itemId = $plan['items'][0]['id'];

        $currentTarget = $this->s->diffs()->planItem($plan['id'], $itemId, 1, 'current_target', 0);
        $this->assertSame([['op' => 'delete', 'text' => 'Now'], ['op' => 'insert', 'text' => 'Before']], $currentTarget['lines']);
        $conflict = $this->s->diffs()->planItem($plan['id'], $itemId, 1, 'conflict', 0);
        $this->assertSame([['op' => 'delete', 'text' => 'After'], ['op' => 'insert', 'text' => 'Now']], $conflict['lines']);
        $this->assertThrows(DomainError::class, fn () => $this->s->diffs()->planItem($plan['id'], $itemId, $this->userId('editor'), 'conflict', 0));
    }

    public function testCsvExportContainsMetadataOnly(): void
    {
        $id = $this->createPost(['post_title' => 'Secret title']);
        wp_update_post(['ID' => $id, 'post_content' => '=HYPERLINK("x")']);
        $this->endRequest();
        $csv = $this->s->historyExport()->csv();
        $this->assertTrue(str_starts_with($csv, 'changeset,created_at_utc'));
        $this->assertFalse(str_contains($csv, 'Secret title'));
        $this->assertFalse(str_contains($csv, 'HYPERLINK'));
        $this->assertTrue(str_contains($csv, ',post_content,'));
    }
}
