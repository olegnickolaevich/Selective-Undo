<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

final class QuickUndoTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        do_action('rest_api_init', rest_get_server());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: mixed}
     */
    private function call(string $method, string $path, array $body = []): array
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        $request = new \WP_REST_Request($method, '/selective-undo/v1' . $route);
        parse_str($query, $params);
        $request->set_query_params($params);

        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }

        $response = rest_do_request($request);
        $data = rest_get_server()->response_to_data($response, false);
        $this->endRequest();

        return [$response->get_status(), $data];
    }

    private function recentEdit(int $postId): string
    {
        [$status, $recent] = $this->call('GET', '/me/recent-changesets?object_id=' . $postId);
        $this->assertSame(200, $status);

        return (string) $recent['items'][0]['id'];
    }

    private function jobCount(): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->s->tables()->jobs}");
    }

    public function testUndoRestoresAtOnceAndRepeatedClickRunsOnce(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Typo']);
        $this->endRequest();
        $changeset = $this->recentEdit($id);
        $key = wp_generate_uuid4();

        [$status, $result] = $this->call('POST', "/changesets/{$changeset}/undo", ['idempotency_key' => $key]);
        $this->assertSame(200, $status);
        $this->assertSame('restored', $result['status']);
        $this->assertSame('completed', $result['job']['status']);
        $this->assertSame('Контакты', get_post($id)->post_title);
        $this->assertCount(1, $this->changes($id, 'restore'));

        // A double click or a retried request sends the same key.
        [, $again] = $this->call('POST', "/changesets/{$changeset}/undo", ['idempotency_key' => $key]);
        $this->assertSame('restored', $again['status']);
        $this->assertSame($result['job']['id'], $again['job']['id']);
        $this->assertSame(1, $this->jobCount());
    }

    public function testLaterChangeOfTheSameFieldLeadsToThePreview(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Typo']);
        $this->endRequest();
        $changeset = $this->recentEdit($id);
        wp_set_current_user($this->userId('editor'));
        wp_update_post(['ID' => $id, 'post_title' => 'Fixed by the editor']);
        $this->endRequest();
        wp_set_current_user(1);

        [, $result] = $this->call('POST', "/changesets/{$changeset}/undo", ['idempotency_key' => wp_generate_uuid4()]);
        $this->assertSame('needs_review', $result['status']);
        $this->assertNotNull($result['plan_id']);
        $this->assertSame('Fixed by the editor', get_post($id)->post_title);
        $this->assertSame(0, $this->jobCount());
    }

    public function testRecentListShowsOnlyOwnOperations(): void
    {
        $id = $this->createPost();
        wp_set_current_user($this->userId('editor'));
        wp_update_post(['ID' => $id, 'post_title' => 'By the editor']);
        $this->endRequest();
        wp_set_current_user(1);

        [, $recent] = $this->call('GET', '/me/recent-changesets?object_id=' . $id);
        $this->assertSame(['created'], array_merge(...array_map(static fn (array $cs): array => $cs['events'], $recent['items'])));
    }
}
