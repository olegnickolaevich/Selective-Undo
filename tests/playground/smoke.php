<?php

/**
 * Smoke test for SQLite / WordPress Playground: record a change, then undo it over REST.
 *
 * Runs in two separate requests, because history is sealed at the end of a request:
 *
 *   php tests/playground/smoke.php <wordpress-dir> record
 *   php tests/playground/smoke.php <wordpress-dir> restore
 *
 * In Playground (tests/playground/ci-blueprint.json) each phase is a runPHP step that
 * defines SU_SMOKE_PHASE and requires this file. Prints "SMOKE OK <phase>" on success;
 * the restore phase also writes it to tests/playground/.smoke-result.
 */

declare(strict_types=1);

if (!defined('SU_SMOKE_PHASE')) {
    define('SU_SMOKE_PHASE', $argv[2] ?? '');
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = '/';
    require rtrim($argv[1] ?? '/wordpress', '/') . '/wp-load.php';
} elseif (!defined('ABSPATH')) {
    require '/wordpress/wp-load.php';
}

$fail = static function (string $message): never {
    echo "SMOKE FAILED: {$message}\n";
    throw new RuntimeException($message);
};

$rest = static function (string $method, string $path, array $body = []): array {
    $request = new WP_REST_Request($method, '/selective-undo/v1' . $path);

    if ($body !== []) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }

    $response = rest_do_request($request);

    return [$response->get_status(), rest_get_server()->response_to_data($response, false)];
};

wp_set_current_user(1);

if (SU_SMOKE_PHASE === 'record') {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Smoke original', 'post_content' => '<p>Body</p>'], true);

    if (is_wp_error($id)) {
        $fail('insert: ' . $id->get_error_message());
    }

    wp_update_post(['ID' => $id, 'post_title' => 'Smoke broken']);
    update_option('sundo_smoke_post', $id, false);
    echo "SMOKE OK record\n";

    return;
}

if (SU_SMOKE_PHASE !== 'restore') {
    $fail('unknown phase');
}

do_action('rest_api_init', rest_get_server());
$id = (int) get_option('sundo_smoke_post');

[$status, $timeline] = $rest('GET', '/objects/post/' . $id . '/changes');
$change = null;

foreach ($status === 200 ? $timeline['items'] : [] as $item) {
    if ($item['event'] === 'update' && $item['field'] === 'post_title') {
        $change = (int) $item['id'];
    }
}

$change ?? $fail("no recorded title change (HTTP {$status})");

[$status, $plan] = $rest('POST', '/restore-plans', ['selection' => ['type' => 'changes', 'change_ids' => [$change]]]);
$status === 201 || $fail("plan: HTTP {$status} " . wp_json_encode($plan));

[$status, $job] = $rest('POST', '/restore-jobs', ['plan_id' => $plan['id'], 'idempotency_key' => wp_generate_uuid4()]);
$status === 201 && $job['status'] === 'completed' || $fail("job: HTTP {$status} " . wp_json_encode($job));

clean_post_cache($id);
get_post($id)->post_title === 'Smoke original' || $fail('title was not restored: ' . get_post($id)->post_title);

[, $overview] = $rest('GET', '/overview');
($overview['restore_mode'] ?? '') === 'compat' || $fail('compatibility mode is not reported');

echo "SMOKE OK restore\n";
// The CLI does not always print runPHP output: CI checks this file instead.
file_put_contents(__DIR__ . '/.smoke-result', "SMOKE OK restore\n");
