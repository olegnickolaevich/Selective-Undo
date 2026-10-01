<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

final class EditorUndoTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        do_action('rest_api_init', rest_get_server());
    }

    private static function p(string $text): string
    {
        return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
    }

    private static function content(string ...$paragraphs): string
    {
        return implode("\n\n", array_map(self::p(...), $paragraphs));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: mixed}
     */
    private function call(string $method, string $route, array $body, int $user = 1): array
    {
        wp_set_current_user($user);
        $request = new \WP_REST_Request($method, $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
        $response = rest_do_request($request);
        $data = rest_get_server()->response_to_data($response, false);
        $this->endRequest();
        wp_set_current_user(1);

        return [$response->get_status(), $data];
    }

    public function testUndoInTheEditorKeepsUnsavedEditsAndMarksTheSave(): void
    {
        $id = $this->createPost(['post_content' => self::content('One', 'Two', 'Three')]);
        wp_update_post(['ID' => $id, 'post_content' => self::content('One', 'Two, broken', 'Three')]);
        $this->endRequest();
        $mistake = $this->updateIds($id, 'post_content')[0];

        // The editor holds an unsaved edit of the third paragraph.
        $editor = self::content('One', 'Two, broken', 'Three, typed but not saved');
        [$status, $preview] = $this->call('POST', "/selective-undo/v1/changes/{$mistake}/merge-preview", ['current' => $editor]);
        $this->assertSame(200, $status);
        $this->assertSame('clean', $preview['status']);
        $this->assertSame(self::content('One', 'Two', 'Three, typed but not saved'), $preview['value']);
        $this->assertSame(self::content('One', 'Two, broken', 'Three'), get_post($id)->post_content, 'nothing is written');

        // The editor saves the result with the intent.
        [$status] = $this->call('POST', "/wp/v2/pages/{$id}", [
            'content' => $preview['value'],
            'selective_undo_intent' => ['reverts' => [$mistake]],
        ]);
        $this->assertSame(200, $status);
        $saved = $this->changes($id, 'update');
        $last = $saved[count($saved) - 1];
        $this->assertSame($mistake, (int) $last['reverts_change_id']);
        $this->assertSame('editor_undo', $this->db->get_var($this->db->prepare(
            "SELECT source FROM {$this->s->tables()->changesets} WHERE id = %d",
            (int) $last['changeset_id']
        )));
    }

    public function testUsersWhoCannotEditThePostAreRefused(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Changed']);
        $this->endRequest();
        $change = $this->updateIds($id, 'post_title')[0];
        $role = get_role('author');
        $role->add_cap('sundo_view_history');
        $role->add_cap('sundo_restore_changes');

        try {
            [$status] = $this->call('POST', "/selective-undo/v1/changes/{$change}/merge-preview", ['current' => 'Changed'], $this->userId('author'));
            $this->assertSame(403, $status);
        } finally {
            $role->remove_cap('sundo_view_history');
            $role->remove_cap('sundo_restore_changes');
        }
    }
}
