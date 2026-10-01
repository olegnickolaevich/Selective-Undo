<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

final class PartialUndoTest extends IntegrationTestCase
{
    private static function p(string $text): string
    {
        return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
    }

    private static function content(string ...$paragraphs): string
    {
        return implode("\n\n", array_map(self::p(...), $paragraphs));
    }

    private function editContent(int $id, string $content, string $user = 'admin'): int
    {
        wp_set_current_user($user === 'admin' ? 1 : $this->userId($user));
        wp_update_post(['ID' => $id, 'post_content' => $content]);
        $this->endRequest();
        wp_set_current_user(1);
        $ids = $this->updateIds($id, 'post_content');

        return $ids[count($ids) - 1];
    }

    public function testUndoOnlyOnePartOfAChange(): void
    {
        $id = $this->createPost(['post_content' => self::content('1', '2', '3', '4', '5')]);
        $change = $this->editContent($id, self::content('1', '2 new', '3', '4', '5 new'));

        $blocks = $this->s->diffs()->changeBlocks($change, 1);
        $parts = array_values(array_filter($blocks['hunks'], static fn (array $h): bool => $h['kind'] === 'change'));
        $this->assertSame(2, count($parts));
        $this->assertSame('5 new', $parts[1]['after'][0]['text']);
        $this->assertSame('Paragraph', $parts[1]['after'][0]['label']);

        $job = $this->start($this->plan(['type' => 'segments', 'change_id' => $change, 'hunks' => [$parts[1]['index']]]));
        $this->assertSame('completed', $job['status']);
        $this->assertSame(self::content('1', '2 new', '3', '4', '5'), get_post($id)->post_content);
    }

    public function testEachBlockIsAttributedToItsAuthor(): void
    {
        $id = $this->createPost(['post_content' => self::content('a', 'b', 'c')]);
        $first = $this->editContent($id, self::content('A', 'b', 'c'));
        $second = $this->editContent($id, self::content('A', 'B', 'c'), 'editor');

        $blame = $this->s->blame()->forPost($id, 1);
        $this->assertTrue($blame['complete']);
        $owners = array_map(static fn (array $b): ?int => $b['change']['id'] ?? null, $blame['blocks']);
        $this->assertSame([$first, $second, null], $owners);
        $this->assertSame('editor', $blame['blocks'][1]['change']['actor']['name'] ?? null);
    }

    public function testBackToAMoment(): void
    {
        $id = $this->createPost(['post_title' => 'T0', 'post_content' => self::content('c0')]);
        $at = static fn (string $time): string => gmdate('Y-m-d') . ' ' . $time;
        $this->moveLastChange($id, 'post_title', $at('10:00:00'), ['post_title' => 'T1']);
        $this->moveLastChange($id, 'post_content', $at('11:00:00'), ['post_content' => self::content('c1')]);
        $this->moveLastChange($id, 'post_title', $at('12:00:00'), ['post_title' => 'T2']);

        $plan = $this->plan(['type' => 'as_of', 'object_id' => $id, 'at' => gmdate('Y-m-d') . 'T10:30:00Z']);
        $this->assertSame(2, $this->planView($plan)['summary']['ready']);
        $this->start($plan);

        $post = get_post($id);
        $this->assertSame('T1', $post->post_title);
        $this->assertSame(self::content('c0'), $post->post_content);
    }

    /**
     * @param array<string, string> $edit
     */
    private function moveLastChange(int $id, string $field, string $createdAt, array $edit): void
    {
        wp_update_post(['ID' => $id] + $edit);
        $this->endRequest();
        $ids = $this->updateIds($id, $field);
        $this->db->update($this->s->tables()->changes, ['created_at' => $createdAt], ['id' => $ids[count($ids) - 1]]);
    }
}
