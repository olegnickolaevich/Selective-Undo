<?php

/**
 * Fills a disposable site with a large history for the load check (specification §12.2):
 * posts, and changes in consistent before/after chains over a shared pool of stored
 * values, written straight into the journal tables.
 *
 *   SU_TEST_SITE=.work/load-site SU_DB_NAME=wpload bin/test-env.sh up
 *   SU_TEST_SITE=.work/load-site SU_DB_NAME=wpload bin/test-env.sh wp eval-file tests/load/seed.php [objects] [changes per object]
 *
 * Never run it on a real site: it truncates the history.
 */


use SelectiveUndo\Bootstrap\Plugin;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Storage\BlobCodec;

if (!defined('WP_CLI') || !str_contains((string) DB_NAME, 'load')) {
    exit("Run with WP-CLI on the load test site only (database name containing \"load\").\n");
}

global $wpdb;
$objects = (int) ($args[0] ?? 100000);
$perObject = (int) ($args[1] ?? 10);
$pool = 20000;
$fields = ['post_title', 'post_content', 'post_excerpt', 'meta:_thumbnail_id'];
$sources = ['block_editor', 'block_editor', 'block_editor', 'quick_edit', 'rest', 'wp_cli', 'cron'];
$t = Plugin::services()->tables();
$started = microtime(true);
$say = static fn (string $what) => WP_CLI::log(sprintf('%6.1fs  %s', microtime(true) - $started, $what));

foreach ($t->own() as $table) {
    $wpdb->query("TRUNCATE TABLE `{$table}`");
}

$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_title LIKE 'Load %'");

/** Inserts rows in multi-row statements. */
$insert = static function (string $table, array $columns, iterable $rows, int $batch = 2000) use ($wpdb): void {
    $head = "INSERT INTO `{$table}` (" . implode(',', $columns) . ') VALUES ';
    $values = [];

    foreach ($rows as $row) {
        $values[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : "'" . esc_sql((string) $v) . "'", $row)) . ')';

        if (count($values) === $batch) {
            $wpdb->query($head . implode(',', $values)) !== false || throw new RuntimeException($wpdb->last_error);
            $values = [];
        }
    }

    if ($values !== []) {
        $wpdb->query($head . implode(',', $values)) !== false || throw new RuntimeException($wpdb->last_error);
    }
};

// Posts: a short body each (the history list never reads it).
$firstId = (int) $wpdb->get_var("SELECT COALESCE(MAX(ID), 0) + 1 FROM {$wpdb->posts}");
$insert($wpdb->posts, ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_type', 'guid'], (static function () use ($objects, $firstId) {
    for ($i = 0; $i < $objects; $i++) {
        $id = $firstId + $i;
        yield [$id, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '<p>Body</p>', "Load {$i}", '', 'publish', "load-{$i}", '', '', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '', $i % 5 === 0 ? 'page' : 'post', ''];
    }
})());
$say("{$objects} posts");

// Stored values: texts of 1-60 KB, compressed as the plugin stores them.
$codec = new BlobCodec(16 << 20);
$hashes = [];
$insert($t->blobs, ['id', 'content_hash', 'encoding', 'payload', 'uncompressed_bytes', 'stored_bytes', 'created_at', 'last_seen_at'], (static function () use ($pool, $codec, &$hashes) {
    for ($b = 1; $b <= $pool; $b++) {
        $text = str_repeat("<!-- wp:paragraph --><p>Value {$b} " . md5((string) $b) . "</p><!-- /wp:paragraph -->\n", 1 + ($b % 600));
        $row = $codec->pack(FieldValue::of($text));
        $hashes[$b] = $row['content_hash'];
        yield [$b, $row['content_hash'], $row['encoding'], $row['payload'], $row['uncompressed_bytes'], $row['stored_bytes'], '2026-01-01 00:00:00', '2026-01-01 00:00:00'];
    }
})(), 200);
$say("{$pool} stored values");

// Change sets of 4 changes; each object gets $perObject changes spread over the last 30 days.
$now = time();
$changesetId = 0;
$changeRows = static function () use ($objects, $perObject, $fields, $pool, &$hashes, $firstId, $now, &$changesetId, $t, $insert, $sources) {
    $sets = [];

    for ($i = 0; $i < $objects; $i++) {
        for ($k = 1; $k <= $perObject; $k++) {
            if (($k - 1) % 4 === 0) {
                $changesetId++;
                $at = gmdate('Y-m-d H:i:s', $now - (int) ((($objects - $i) * $perObject - $k) * (30 * DAY_IN_SECONDS) / ($objects * $perObject)));
                $sets[] = [$changesetId, wp_generate_uuid4(), wp_generate_uuid4(), 1 + ($i % 3), 'request', $sources[$i % count($sources)], 'request', 'sealed', 'verified', 4, 1, $at, $at, $at];

                if (count($sets) === 2000) {
                    $insert($t->changesets, ['id', 'uuid', 'request_uuid', 'actor_user_id', 'kind', 'source', 'grouping_mode', 'status', 'quality', 'change_count', 'object_count', 'created_at', 'updated_at', 'sealed_at'], $sets);
                    $sets = [];
                }
            }

            $field = $fields[($k - 1) % count($fields)];
            // The chain of one field: the "after" of one change is the "before" of the next.
            $n = intdiv($k - 1, count($fields));
            $before = 1 + (($i * 13 + ($k - 1) % count($fields) * 7 + $n) % $pool);
            $after = 1 + (($i * 13 + ($k - 1) % count($fields) * 7 + $n + 1) % $pool);

            yield [$changesetId, ($k - 1) % 4 + 1, 'update', 'post', $i % 5 === 0 ? 'page' : 'post', $firstId + $i,
                str_starts_with($field, 'meta:') ? 'core/post-meta' : 'core/post-fields', 1, $field,
                $before, $after, $hashes[$before], $hashes[$after], 'verified', 1, $k, $at ?? gmdate('Y-m-d H:i:s')];
        }
    }

    if ($sets !== []) {
        $insert($t->changesets, ['id', 'uuid', 'request_uuid', 'actor_user_id', 'kind', 'source', 'grouping_mode', 'status', 'quality', 'change_count', 'object_count', 'created_at', 'updated_at', 'sealed_at'], $sets);
    }
};
$insert($t->changes, ['changeset_id', 'sequence_no', 'event', 'object_type', 'object_subtype', 'object_id', 'adapter_key', 'adapter_version', 'field_key', 'before_blob_id', 'after_blob_id', 'before_hash', 'after_hash', 'quality', 'restorable', 'object_seq', 'created_at'], $changeRows());
$say(($objects * $perObject) . " changes in {$changesetId} change sets");

$wpdb->query("INSERT INTO {$t->objectVersions} (object_type, object_id, last_seq) SELECT object_type, object_id, MAX(object_seq) FROM {$t->changes} GROUP BY object_type, object_id");
$wpdb->query("ANALYZE TABLE {$t->changes}, {$t->changesets}, {$t->blobs}");
$say('done; first post ID ' . $firstId);
