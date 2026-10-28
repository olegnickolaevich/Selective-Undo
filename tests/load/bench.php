<?php

/**
 * Load check (specification §12.2) on the site filled by tests/load/seed.php:
 *
 *   SU_TEST_SITE=.work/load-site SU_DB_NAME=wpload bin/test-env.sh wp eval-file tests/load/bench.php [saves]
 *
 * Prints the median and the slowest of several runs per scenario, and the budget.
 */

use SelectiveUndo\Bootstrap\Plugin;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\FieldDefinition;

if (!defined('WP_CLI') || !str_contains((string) DB_NAME, 'load')) {
    exit("Run with WP-CLI on the load test site only (database name containing \"load\").\n");
}

global $wpdb;
$s = Plugin::services();
$t = $s->tables();
wp_set_current_user(1);
$saves = (int) ($args[0] ?? 300);

/**
 * @return array{0: float, 1: float} median and slowest, in ms
 */
$time = static function (callable $fn, int $runs = 7): array {
    $ms = [];

    for ($i = 0; $i < $runs; $i++) {
        wp_cache_flush();
        $start = hrtime(true);
        $fn();
        $ms[] = (hrtime(true) - $start) / 1e6;
    }

    sort($ms);

    return [$ms[intdiv(count($ms), 2)], $ms[count($ms) - 1]];
};
$report = static function (string $name, array $r, float $budget) {
    WP_CLI::log(sprintf('%-58s median %8.1f ms   max %8.1f ms   budget %6.0f ms   %s', $name, $r[0], $r[1], $budget, $r[0] <= $budget ? 'OK' : 'SLOW'));
};

$changes = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t->changes}");
$objects = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t->objectVersions}");
WP_CLI::log("Journal: {$changes} changes, {$objects} objects");

// History list (≤ 300 ms per page).
$history = $s->history();
$report('History: first page', $time(fn () => $history->list(1, [], null, 20)), 300);
$cursor = null;

for ($p = 0; $p < 50; $p++) {
    $cursor = $history->list(1, [], $cursor, 20)['next_cursor'];
}

$report('History: page 51', $time(fn () => $history->list(1, [], $cursor, 20)), 300);
$report('History: source = quick_edit', $time(fn () => $history->list(1, ['source' => 'quick_edit'], null, 20)), 300);
$report('History: user 3', $time(fn () => $history->list(1, ['actor' => 3], null, 20)), 300);
$report('History: one week, 20 days ago', $time(fn () => $history->list(1, ['from' => gmdate('c', time() - 20 * DAY_IN_SECONDS), 'to' => gmdate('c', time() - 13 * DAY_IN_SECONDS)], null, 20)), 300);
$mid = (int) $wpdb->get_var("SELECT object_id FROM {$t->objectVersions} ORDER BY object_id LIMIT 1 OFFSET " . intdiv($objects, 2));
$report('History: search by ID', $time(fn () => $history->list(1, ['search' => (string) $mid], null, 20)), 300);
$report('History: search by title', $time(fn () => $history->list(1, ['search' => 'Load 5000'], null, 20)), 300);
$report('History: pages only', $time(fn () => $history->list(1, ['subtype' => 'page'], null, 20)), 300);
$report('Item history (timeline)', $time(fn () => $history->objectTimeline(new ObjectRef('post', $mid), 1, null, 20)), 300);
$oldSet = (string) $wpdb->get_var("SELECT uuid FROM {$t->changesets} ORDER BY id LIMIT 1 OFFSET 1000");
$report('One operation', $time(fn () => $history->changeset($oldSet, 1)), 300);

// Restore plan of one change deep in an object's history (≤ 300 ms).
$change = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t->changes} WHERE object_id = %d AND field_key = 'post_title' ORDER BY object_seq LIMIT 1", $mid));
$report('Restore plan: one change', $time(fn () => $s->buildPlan()->handle(1, ['type' => 'changes', 'change_ids' => [$change]]), 5), 300);

// Plan with merge: 500 blocks, 100 edits after the change undone (NFR-3, ≤ 300 ms).
$blocks = static fn (array $texts): string => implode("\n\n", array_map(static fn (string $x): string => "<!-- wp:paragraph -->\n<p>{$x}</p>\n<!-- /wp:paragraph -->", $texts));
$texts = array_map(static fn (int $i): string => "Paragraph {$i} " . str_repeat('lorem ipsum ', 12), range(1, 500));
$post = wp_insert_post(['post_title' => 'Merge 500', 'post_status' => 'publish', 'post_content' => $blocks($texts)]);
$s->capture()->sealRequestChangesets();
$s->requestContext()->reset();
$texts[0] .= ' first edit';
wp_update_post(['ID' => $post, 'post_content' => $blocks($texts)]);
$s->capture()->sealRequestChangesets();
$s->requestContext()->reset();
$first = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM {$t->changes} WHERE object_id = %d AND field_key = 'post_content'", $post));

for ($e = 1; $e <= 100; $e++) {
    $texts[$e * 4] .= " edit {$e}";
    wp_update_post(['ID' => $post, 'post_content' => $blocks($texts)]);
    $s->capture()->sealRequestChangesets();
    $s->requestContext()->reset();
}

$plan = null;
$report('Restore plan: merge, 500 blocks, 100 later edits', $time(function () use ($s, $first, &$plan) {
    $plan = $s->buildPlan()->handle(1, ['type' => 'changes', 'change_ids' => [$first]]);
}, 5), 300);
$status = $s->planView()->forUser((string) $plan, 1)['items'][0]['status'] ?? '?';
WP_CLI::log("  (merged plan item: {$status})");

// Saves: 4 fields + 5 recorded meta keys, text of 20-200 KB (NFR-1: p95 ≤ 15 ms extra; NFR-2: ≤ 2 ms without changes).
add_filter('selective_undo/meta_fields', static fn (array $f): array => [...$f, ...array_map(static fn (int $i) => new FieldDefinition("meta:load_{$i}", "Load {$i}", 'text'), range(1, 5))]);
$s->metaFields()->flush();
$ids = [];

for ($i = 0; $i < 20; $i++) {
    $ids[] = wp_insert_post(['post_title' => "Save {$i}", 'post_status' => 'publish', 'post_content' => str_repeat('<p>' . wp_generate_password(80, false) . '</p>', 200 + $i * 90)]);
}

$s->capture()->sealRequestChangesets();
$s->requestContext()->reset();
$run = static function (bool $change, int $n) use ($ids, $s): array {
    $ms = [];

    for ($i = 0; $i < $n; $i++) {
        $id = $ids[$i % count($ids)];
        $suffix = $change ? (string) $i : 'same';
        $start = hrtime(true);
        wp_update_post(['ID' => $id, 'post_title' => "Save {$id} {$suffix}", 'post_excerpt' => "Excerpt {$suffix}", 'post_name' => "save-{$id}", 'post_content' => get_post_field('post_content', $id, 'raw') . ($change ? "<p>{$i}</p>" : '')]);

        for ($m = 1; $m <= 5; $m++) {
            update_post_meta($id, "load_{$m}", "value {$m} {$suffix}");
        }

        $ms[] = (hrtime(true) - $start) / 1e6;
        $s->capture()->sealRequestChangesets();
        $s->requestContext()->reset();
    }

    sort($ms);

    return ['p50' => $ms[intdiv(count($ms), 2)], 'p95' => $ms[(int) floor(count($ms) * 0.95)], 'p99' => $ms[(int) floor(count($ms) * 0.99)]];
};
$settings = $s->settings();
$on = $run(true, $saves);
$same = $run(false, 50);
$options = (array) get_option($settings::OPTION);
update_option($settings::OPTION, ['capture_enabled' => false] + $options);
$settings->flush();
$off = $run(true, $saves);
$sameOff = $run(false, 50);
update_option($settings::OPTION, $options);
$settings->flush();

WP_CLI::log(sprintf(
    'Save with 9 changed fields, %d saves: recording p50 %.1f / p95 %.1f / p99 %.1f ms; without %.1f / %.1f / %.1f ms; extra p95 %.1f ms (budget 15), p99 %.1f ms (budget 40)',
    $saves, $on['p50'], $on['p95'], $on['p99'], $off['p50'], $off['p95'], $off['p99'], $on['p95'] - $off['p95'], $on['p99'] - $off['p99']
));
WP_CLI::log(sprintf('Save without changes: extra p50 %.1f ms (budget 2)', $same['p50'] - $sameOff['p50']));

// Reconciliation: one heartbeat checks a batch of 500 items within 10 s; at one heartbeat per
// 5 min, 50 000 items take 100 heartbeats (8 h 20 min, budget 24 h). Generated values do not
// match the posts, so the first 500 items are first given their last recorded values.
$s->circuitBreaker()->reset();
delete_option('selective_undo_circuit');
$s->siteFingerprint()->accept();
$batch = array_map('intval', $wpdb->get_col("SELECT object_id FROM {$t->objectVersions} ORDER BY object_id LIMIT 500"));

foreach ($batch as $id) {
    $set = [];

    foreach ($s->journalReader()->latestStoredValues(new ObjectRef('post', $id)) as $field => $v) {
        $value = $s->blobs()->loadVerified($v['blob_id'], $v['hash'])->value;

        if (str_starts_with($field, 'meta:')) {
            $wpdb->delete($wpdb->postmeta, ['post_id' => $id, 'meta_key' => substr($field, 5)]);
            $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => substr($field, 5), 'meta_value' => $value]);
        } else {
            $set[$field] = $value;
        }
    }

    $wpdb->update($wpdb->posts, $set, ['ID' => $id]);
}

update_option(\SelectiveUndo\Application\Capture\ReconciliationScanner::CURSOR_OPTION, $batch[0] - 1, false);
wp_cache_flush();
$start = microtime(true);
$r = $s->reconciliation()->run(10.0);
$elapsed = microtime(true) - $start;
WP_CLI::log(sprintf('Reconciliation: %d items in %.1f s (budget 10 s per heartbeat), %d with differences', $r['checked'], $elapsed, $r['drifted']));
