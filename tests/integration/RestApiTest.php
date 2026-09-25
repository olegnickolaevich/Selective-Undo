<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

final class RestApiTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        do_action('rest_api_init', rest_get_server());
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     *
     * @return array{0: int, 1: mixed}
     */
    private function call(string $method, string $path, array $body = [], array $query = [], int $user = 1): array
    {
        wp_set_current_user($user);
        $request = new \WP_REST_Request($method, '/selective-undo/v1' . $path);

        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }

        foreach ($query as $k => $v) {
            $request->set_query_params(array_merge($request->get_query_params(), [$k => $v]));
        }

        $response = rest_do_request($request);
        $data = rest_get_server()->response_to_data($response, false);
        $this->endRequest();
        wp_set_current_user(1);

        return [$response->get_status(), $data];
    }

    public function testAnonymousAndUnprivilegedUsersAreRejected(): void
    {
        foreach (['/overview', '/changesets', '/settings', '/health', '/restore-jobs'] as $path) {
            [$status] = $this->call('GET', $path, [], [], 0);
            $this->assertSame(401, $status, $path);
            [$status] = $this->call('GET', $path, [], [], $this->userId('editor'));
            $this->assertSame(403, $status, $path);
        }

        [$status] = $this->call('POST', '/restore-plans', ['selection' => ['type' => 'changes', 'change_ids' => [1]]], [], $this->userId('editor'));
        $this->assertSame(403, $status);
    }

    public function testFullRestoreFlowOverRest(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Broken by import']);
        $this->endRequest();

        [$status, $list] = $this->call('GET', '/changesets', [], ['kind' => 'edit']);
        $this->assertSame(200, $status);
        $changeset = $list['items'][0];
        [, $detail] = $this->call('GET', '/changesets/' . $changeset['id']);
        $changeId = $detail['objects'][0]['changes'][0]['id'];

        [$status, $diff] = $this->call('GET', '/changes/' . $changeId . '/diff');
        $this->assertSame(200, $status);
        $this->assertSame([['op' => 'delete', 'text' => 'Контакты'], ['op' => 'insert', 'text' => 'Broken by import']], $diff['lines']);

        [$status, $plan] = $this->call('POST', '/restore-plans', ['selection' => ['type' => 'changes', 'change_ids' => [$changeId]]]);
        $this->assertSame(201, $status);
        $this->assertSame(1, $plan['summary']['ready']);
        $this->assertContains('comments', $plan['untouched']);

        [$status, $itemDiff] = $this->call('GET', '/restore-plans/' . $plan['id'] . '/items/' . $plan['items'][0]['id'] . '/diff');
        $this->assertSame(200, $status);
        $this->assertSame('current_target', $itemDiff['mode']);

        $key = wp_generate_uuid4();
        [$status, $job] = $this->call('POST', '/restore-jobs', ['plan_id' => $plan['id'], 'idempotency_key' => $key]);
        $this->assertSame(201, $status);
        $this->assertSame('completed', $job['status']);
        $this->assertSame('Контакты', get_post($id)->post_title);

        [$status, $again] = $this->call('POST', '/restore-jobs', ['plan_id' => $plan['id'], 'idempotency_key' => $key]);
        $this->assertSame(200, $status, 'replay of the same request');
        $this->assertSame($job['id'], $again['id']);

        [$status, $items] = $this->call('GET', '/restore-jobs/' . $job['id'] . '/items');
        $this->assertSame(200, $status);
        $this->assertSame('restored', $items['items'][0]['status']);

        [$status, $jobs] = $this->call('GET', '/restore-jobs');
        $this->assertSame($job['id'], $jobs['items'][0]['id']);

        [$status, $timeline] = $this->call('GET', '/objects/post/' . $id . '/changes');
        $this->assertSame('restore', $timeline['items'][0]['event']);
    }

    public function testErrorsHaveUniformShape(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'X']);
        $this->endRequest();
        [, $plan] = $this->call('POST', '/restore-plans', ['selection' => ['type' => 'changes', 'change_ids' => $this->updateIds($id)]]);
        $this->db->query($this->db->prepare("UPDATE {$this->s->tables()->plans} SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE uuid = %s", $plan['id']));

        [$status, $error] = $this->call('POST', '/restore-jobs', ['plan_id' => $plan['id'], 'idempotency_key' => wp_generate_uuid4()]);
        $this->assertSame(409, $status);
        $this->assertSame('su_plan_expired', $error['code']);
        $this->assertSame(409, $error['data']['status']);
        $this->assertNotNull($error['data']['request_id']);
        $this->assertTrue($error['message'] !== '');
    }

    public function testArgumentValidation(): void
    {
        [$status, $error] = $this->call('POST', '/restore-plans', ['selection' => ['type' => 'changes', 'change_ids' => range(1, 501)]]);
        $this->assertSame(400, $status);
        $this->assertSame('rest_invalid_param', $error['code']);

        [$status] = $this->call('POST', '/restore-plans', ['selection' => ['type' => 'changes', 'change_ids' => [1], 'target_value' => '<script>']]);
        $this->assertSame(400, $status, 'clients cannot send values');

        [$status] = $this->call('POST', '/restore-jobs', ['plan_id' => 'not-a-uuid', 'idempotency_key' => wp_generate_uuid4()]);
        $this->assertSame(400, $status);

        [$status, $error] = $this->call('GET', '/changesets', [], ['cursor' => 'bad!']);
        $this->assertSame(400, $status);
    }

    public function testPlansAndJobsOfOtherUsersAreNotVisible(): void
    {
        $role = get_role('editor');
        $role->add_cap('sundo_view_history');
        $role->add_cap('sundo_restore_changes');
        $editor = $this->userId('editor');

        try {
            $id = $this->createPost();
            wp_update_post(['ID' => $id, 'post_title' => 'X']);
            $this->endRequest();
            [, $plan] = $this->call('POST', '/restore-plans', ['selection' => ['type' => 'changes', 'change_ids' => $this->updateIds($id)]]);
            [$status] = $this->call('GET', '/restore-plans/' . $plan['id'], [], [], $editor);
            $this->assertSame(404, $status);
            [$status] = $this->call('POST', '/restore-jobs', ['plan_id' => $plan['id'], 'idempotency_key' => wp_generate_uuid4()], [], $editor);
            $this->assertSame(404, $status, 'cannot run another user\'s plan');

            [, $job] = $this->call('POST', '/restore-jobs', ['plan_id' => $plan['id'], 'idempotency_key' => wp_generate_uuid4()]);
            [$status] = $this->call('GET', '/restore-jobs/' . $job['id'], [], [], $editor);
            $this->assertSame(404, $status);
            [$status, $list] = $this->call('GET', '/restore-jobs', [], [], $editor);
            $this->assertSame([], $list['items']);
        } finally {
            $role->remove_cap('sundo_view_history');
            $role->remove_cap('sundo_restore_changes');
        }
    }

    public function testSettingsAndRoles(): void
    {
        [$status, $settings] = $this->call('GET', '/settings');
        $this->assertSame(200, $status);
        $this->assertSame(7, $settings['limits']['retention_max_days']);
        $names = array_column($settings['post_types'], 'name');
        $this->assertContains('post', $names);
        $this->assertNotContains('attachment', $names);
        $this->assertTrue($settings['roles']['administrator']['caps']['sundo_manage_settings']);

        [$status, $error] = $this->call('PATCH', '/settings', ['settings' => ['retention_days' => 60]]);
        $this->assertSame(400, $status);
        $this->assertSame('su_invalid_settings', $error['code']);

        [$status, $updated] = $this->call('PATCH', '/settings', [
            'settings' => ['capture_enabled' => false],
            'roles' => ['editor' => ['sundo_view_history' => true], 'administrator' => ['sundo_manage_settings' => false]],
        ]);
        $this->assertSame(200, $status);
        $this->assertFalse($updated['settings']['capture_enabled']);
        $this->assertTrue($updated['roles']['editor']['caps']['sundo_view_history']);
        $this->assertTrue($updated['roles']['administrator']['caps']['sundo_manage_settings'], 'admins cannot lock themselves out');
        $this->assertSame(1, (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->s->tables()->gaps} WHERE reason = 'capture_disabled' AND ended_at IS NULL"));

        $this->call('PATCH', '/settings', ['settings' => ['capture_enabled' => true], 'roles' => ['editor' => ['sundo_view_history' => false]]]);
        $this->assertSame(0, (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->s->tables()->gaps} WHERE reason = 'capture_disabled' AND ended_at IS NULL"));
        $this->assertFalse(get_role('editor')->has_cap('sundo_view_history'));
    }

    public function testPurgeHealthDiagnosticsAndExportEndpoints(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'X']);
        $this->endRequest();

        [$status, $preview] = $this->call('POST', '/history/purge-preview', ['scope' => 'object', 'object_id' => $id]);
        $this->assertSame(200, $status);
        [$status, $result] = $this->call('POST', '/history/purge', ['token' => $preview['token'], 'confirmation' => 'DELETE']);
        $this->assertSame(200, $status);
        $this->assertSame(2, $result['deleted_changes']);

        [$status, $health] = $this->call('GET', '/health');
        $this->assertSame(200, $status);
        $this->assertTrue(count($health['checks']) >= 8);

        [$status, $diagnostics] = $this->call('GET', '/diagnostics');
        $this->assertSame(200, $status);
        $this->assertSame(SELECTIVE_UNDO_VERSION, $diagnostics['versions']['plugin']);

        [$status, $export] = $this->call('GET', '/history/export');
        $this->assertSame(200, $status);
        $this->assertTrue(str_ends_with($export['filename'], '.csv'));

        [$status, $overview] = $this->call('GET', '/overview');
        $this->assertSame(200, $status);
        $this->assertTrue($overview['user']['can']['restore']);
        $this->assertSame(1, $overview['limits']['max_objects_per_plan']);
    }
}
