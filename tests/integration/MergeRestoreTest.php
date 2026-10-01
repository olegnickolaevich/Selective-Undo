<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

final class MergeRestoreTest extends IntegrationTestCase
{
    private static function p(string $text): string
    {
        return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
    }

    private static function content(string $first, string $second, string $third): string
    {
        return implode("\n\n", [self::p($first), self::p($second), self::p($third)]);
    }

    private function edit(int $id, string $content, string $user = 'admin'): int
    {
        wp_set_current_user($user === 'admin' ? 1 : $this->userId($user));
        wp_update_post(['ID' => $id, 'post_content' => $content]);
        $this->endRequest();
        wp_set_current_user(1);
        $ids = $this->updateIds($id, 'post_content');

        return $ids[count($ids) - 1];
    }

    public function testUndoOfOneParagraphKeepsALaterEditOfAnother(): void
    {
        $id = $this->createPost(['post_content' => self::content('One', 'Two', 'Three')]);
        $mistake = $this->edit($id, self::content('One', 'Two, broken', 'Three'));
        $this->edit($id, self::content('One', 'Two, broken', 'Three, improved'), 'editor');

        $planId = $this->plan(['type' => 'changes', 'change_ids' => [$mistake]]);
        $item = $this->planView($planId)['items'][0];
        $this->assertSame('ready', $item['status']);
        $this->assertTrue(in_array('merged_with_later_changes', $item['warnings'], true));

        $job = $this->start($planId);
        $this->assertSame('completed', $job['status']);
        // Unchanged blocks keep every byte.
        $this->assertSame(self::content('One', 'Two', 'Three, improved'), get_post($id)->post_content);

        // Undoing that restore brings back the text as it was before it.
        $restore = $this->changes($id, 'restore');
        $back = $this->start($this->plan(['type' => 'changes', 'change_ids' => [(int) $restore[0]['id']]]));
        $this->assertSame('completed', $back['status']);
        $this->assertSame(self::content('One', 'Two, broken', 'Three, improved'), get_post($id)->post_content);
    }

    public function testSameParagraphChangedTwiceIsDecidedByTheUser(): void
    {
        $id = $this->createPost(['post_content' => self::content('One', 'Two', 'Three')]);
        $mistake = $this->edit($id, self::content('One', 'Two, broken', 'Three'));
        $this->edit($id, self::content('One', 'Two, broken and edited', 'Three, improved'), 'editor');

        $planId = $this->plan(['type' => 'changes', 'change_ids' => [$mistake]]);
        $item = $this->planView($planId)['items'][0];
        $this->assertSame('conflict', $item['status']);
        $this->assertSame('merge_conflict', $item['reason_code']);
        $this->assertSame([['index' => 0, 'label' => 'paragraph']], $item['merge']['conflicts']);

        $texts = $this->s->mergeView()->conflicts($planId, $item['id'], 1);
        $this->assertSame('Two, broken and edited', $texts[0]['current_text']);
        $this->assertSame('Two', $texts[0]['restored_text']);

        $resolved = $this->plan([
            'type' => 'changes',
            'change_ids' => [$mistake],
            'resolutions' => ["post:{$id}:post_content" => [0 => 'restored']],
        ]);
        $item = $this->planView($resolved)['items'][0];
        $this->assertSame('ready', $item['status']);
        $this->assertTrue(in_array('merge_conflicts_resolved', $item['warnings'], true));

        $this->start($resolved);
        $this->assertSame(self::content('One', 'Two', 'Three, improved'), get_post($id)->post_content);
    }
}
