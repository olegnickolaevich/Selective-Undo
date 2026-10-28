<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Integrations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Application\Capture\RequestContext;
use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterCapabilities;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\CaptureSink;
use SelectiveUndo\Domain\Contracts\CapturingAdapter;
use SelectiveUndo\Domain\Contracts\DescribesFields;
use SelectiveUndo\Domain\Contracts\FieldDefinition;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;
use SelectiveUndo\Infrastructure\WordPress\CacheInvalidator;
use SelectiveUndo\Infrastructure\WordPress\Capabilities;
use SelectiveUndo\Infrastructure\WordPress\FieldsSnapshot;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;

/**
 * SEO fields of All in One SEO ("aioseo:<column>"), kept in its own table
 * {prefix}aioseo_posts, one row per post. A NULL or empty column is a missing value
 * (AIOSEO then uses its defaults); others are the column text.
 *
 * AIOSEO writes the table with its own queries and no hooks of its own on most
 * paths, so writes are observed on the query filter: the row is read right before
 * the first write of a request and again when AIOSEO reports the save, or at the
 * end of the request.
 */
final class AioseoAdapter implements AdapterInterface, CapturingAdapter, DescribesFields, PresentsValues
{
    public const KEY = 'integration/aioseo';
    public const VERSION = 1;

    /** Columns AIOSEO mirrors into post meta for translation plugins. */
    private const MIRRORED = [
        'title' => '_aioseo_title',
        'description' => '_aioseo_description',
        'og_title' => '_aioseo_og_title',
        'og_description' => '_aioseo_og_description',
        'twitter_title' => '_aioseo_twitter_title',
        'twitter_description' => '_aioseo_twitter_description',
    ];

    private const FLAGS = ['robots_default', 'robots_noindex', 'robots_nofollow'];

    /** @var array<int, array<string, FieldValue>> post ID => columns read before the first write */
    private array $pending = [];
    /** @var list<string>|null */
    private ?array $columns = null;
    private bool $reading = false;

    public function __construct(
        private readonly \wpdb $db,
        private readonly TrackingPolicy $policy,
        private readonly RequestContext $request,
        private readonly CacheInvalidator $caches,
    ) {
    }

    public static function available(): bool
    {
        return function_exists('aioseo');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function acceptsVersion(int $version): bool
    {
        return $version === self::VERSION;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            fields: array_map(static fn (string $column): string => 'aioseo:' . $column, $this->columns()),
            requiresTransactions: true,
            supportsBackground: true,
            hasLinkedData: false,
            knownSideEffects: ['object_cache', 'page_cache', 'aioseo_insert_post'],
            idempotent: true,
            maxValueBytes: null,
        );
    }

    public function supports(ObjectRef $object, string $fieldKey): bool
    {
        return $object->type === 'post' && str_starts_with($fieldKey, 'aioseo:')
            && in_array(substr($fieldKey, 7), $this->columns(), true)
            && $this->policy->isTrackedPostType($object->subtype);
    }

    public function fieldLabel(string $fieldKey): string
    {
        $labels = self::labels();
        $label = $labels[substr($fieldKey, 7)] ?? null;

        return $label !== null ? 'All in One SEO: ' . $label : $fieldKey;
    }

    public function fields(ObjectRef $object): array
    {
        $labels = self::labels();

        return array_map(static fn (string $column): FieldDefinition => new FieldDefinition(
            'aioseo:' . $column,
            $labels[$column],
            in_array($column, self::FLAGS, true) ? 'raw' : 'text',
            group: 'All in One SEO',
        ), $this->columns());
    }

    public function registerCapture(CaptureSink $sink): void
    {
        add_filter('query', function (mixed $sql) use ($sink): mixed {
            $postId = $this->reading || !is_string($sql) ? null : self::writtenPostId($sql, $this->table());

            if ($postId !== null && !isset($this->pending[$postId]) && $sink->isActive() && !$this->request->isRestoring() && $this->watched($postId)) {
                $this->pending[$postId] = $this->readRow($postId);
            }

            return $sql;
        }, PHP_INT_MAX);

        add_action('aioseo_insert_post', fn (mixed $postId) => $this->flush($sink, (int) $postId), PHP_INT_MAX, 1);
        // Before the post observer and the sealing of the request's change sets.
        add_action('shutdown', function () use ($sink): void {
            foreach (array_keys($this->pending) as $postId) {
                $this->flush($sink, $postId);
            }
        }, -1);
    }

    public function read(ObjectRef $object): ?ObjectSnapshot
    {
        $post = $this->db->get_row($this->db->prepare("SELECT post_type, post_status FROM {$this->db->posts} WHERE ID = %d", $object->id), ARRAY_A);

        if (!is_array($post)) {
            return null;
        }

        $row = $this->readRow($object->id);

        return new FieldsSnapshot(
            $object->id,
            (string) $post['post_type'],
            static fn (string $field): FieldValue => $row[substr($field, 7)] ?? FieldValue::missing(),
            (string) $post['post_status']
        );
    }

    public function lockAndRead(RestoreTransaction $tx, ObjectRef $object): ?ObjectSnapshot
    {
        $post = $tx->select("SELECT post_type, post_status FROM `{$this->db->posts}` WHERE ID = ?", 'i', [$object->id]);

        if ($post === []) {
            return null;
        }

        $columns = $this->columns();
        $rows = $tx->select(
            'SELECT ' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)) . " FROM `{$this->table()}` WHERE post_id = ? ORDER BY id LIMIT 1 FOR UPDATE",
            'i',
            [$object->id]
        );
        $row = self::toValues($columns, $rows[0] ?? []);

        return new FieldsSnapshot(
            $object->id,
            (string) $post[0]['post_type'],
            static fn (string $field): FieldValue => $row[substr($field, 7)] ?? FieldValue::missing(),
            (string) $post[0]['post_status']
        );
    }

    public function authorize(int $userId, ObjectRef $object, string $action): bool
    {
        $cap = $action === 'view' ? Capabilities::VIEW_OBJECT : Capabilities::RESTORE_OBJECT;

        return user_can($userId, $cap, $object->id, $object->subtype);
    }

    public function validateState(ObjectSnapshot $current, string $fieldKey): ?string
    {
        assert($current instanceof FieldsSnapshot);

        return match (true) {
            !in_array(substr($fieldKey, 7), $this->columns(), true) => 'field_not_supported',
            !$this->policy->isTrackedPostType($current->objectSubtype()) => 'post_type_not_tracked',
            $current->status === 'trash' => 'object_trashed',
            default => null,
        };
    }

    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string
    {
        if ($target->exists && !is_string($target->value)) {
            return 'target_type_mismatch';
        }

        // AIOSEO saves these as plain text; a direct write must not bring back markup.
        if ($target->exists && !user_can($actorUserId, 'unfiltered_html') && wp_kses_post((string) $target->value) !== $target->value) {
            return 'requires_unfiltered_html';
        }

        return null;
    }

    public function warnings(ObjectSnapshot $current, array $fieldKeys, int $viewerUserId): array
    {
        return [];
    }

    public function write(RestoreTransaction $tx, ObjectSnapshot $current, array $values): void
    {
        $set = [];

        foreach ($values as $field => $value) {
            $column = substr($field, 7);

            if (!in_array($column, $this->columns(), true) || ($value->exists && !is_string($value->value))) {
                throw new \LogicException('AIOSEO column is not restorable.');
            }

            $set[$column] = $value->exists ? (string) $value->value : (in_array($column, self::FLAGS, true) ? self::flagDefault($column) : null);
        }

        $now = gmdate('Y-m-d H:i:s');
        $exists = $tx->select("SELECT id FROM `{$this->table()}` WHERE post_id = ? ORDER BY id LIMIT 1", 'i', [$current->objectId()]);

        if ($exists !== []) {
            $assign = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($set)));
            $tx->write(
                "UPDATE `{$this->table()}` SET {$assign}, `updated` = ? WHERE id = ?",
                str_repeat('s', count($set)) . 'si',
                [...array_values($set), $now, (int) $exists[0]['id']]
            );
        } else {
            $names = implode(', ', array_map(static fn (string $c): string => "`{$c}`", array_keys($set)));
            $marks = implode(', ', array_fill(0, count($set), '?'));

            if ($tx->write(
                "INSERT INTO `{$this->table()}` (post_id, {$names}, created, updated) VALUES (?, {$marks}, ?, ?)",
                'i' . str_repeat('s', count($set)) . 'ss',
                [$current->objectId(), ...array_values($set), $now, $now]
            ) !== 1) {
                throw new StorageError('aioseo_insert_failed');
            }
        }

        // The meta copies translation plugins read.
        foreach (array_intersect_key(self::MIRRORED, $set) as $column => $metaKey) {
            $tx->write("DELETE FROM `{$this->db->postmeta}` WHERE post_id = ? AND meta_key = ?", 'is', [$current->objectId(), $metaKey]);
            $tx->write("INSERT INTO `{$this->db->postmeta}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)", 'iss', [$current->objectId(), $metaKey, (string) $set[$column]]);
        }
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        wp_cache_delete($object->id, 'post_meta');
        $this->caches->purgePost($object->id);

        // AIOSEO refreshes what depends on the post's SEO data (sitemaps, revisions).
        $this->request->whileRestoring(static function () use ($object): void {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- AIOSEO's action, fired as its own save does.
            do_action('aioseo_insert_post', $object->id);
        });
    }

    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string
    {
        $column = substr($fieldKey, 7);

        if (in_array($column, self::FLAGS, true)) {
            $flag = $value->exists ? $value->value : self::flagDefault($column);

            return $flag === '1' ? __('Yes', 'selective-undo') : __('No', 'selective-undo');
        }

        return is_string($value->value) ? $value->value : '';
    }

    /**
     * The post whose row an AIOSEO write statement sets. AIOSEO quotes integers in
     * WP-CLI only: on web requests the post ID is a bare number.
     */
    public static function writtenPostId(string $sql, string $table): ?int
    {
        if (stripos($sql, $table) === false
            || !preg_match('/^\s*(?:INSERT|UPDATE|REPLACE)\b.*?\b' . preg_quote($table, '/') . '\b.*?`?post_id`?\s*=\s*\'?(\d+)/is', $sql, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    private function flush(CaptureSink $sink, int $postId): void
    {
        if (!isset($this->pending[$postId])) {
            return;
        }

        $before = $this->pending[$postId];
        unset($this->pending[$postId]);
        $after = $this->readRow($postId);
        $changes = [];

        foreach ($after as $column => $now) {
            if (!$before[$column]->equals($now)) {
                $changes['aioseo:' . $column] = [$before[$column], $now];
            }
        }

        if ($changes !== []) {
            $sink->record($this, new ObjectRef('post', $postId, (string) get_post_type($postId)), $changes, CaptureQuality::Verified);
        }
    }

    private function watched(int $postId): bool
    {
        $post = get_post($postId);

        return $post instanceof \WP_Post && $post->post_status !== 'auto-draft'
            && !$this->request->inPostLifecycle()
            && $this->policy->shouldCapture($postId, $post->post_type);
    }

    /**
     * @return array<string, FieldValue>
     */
    private function readRow(int $postId): array
    {
        $columns = $this->columns();
        $this->reading = true;

        try {
            $row = $this->db->get_row($this->db->prepare(
                'SELECT ' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)) . " FROM `{$this->table()}` WHERE post_id = %d ORDER BY id LIMIT 1",
                $postId
            ), ARRAY_A);
        } finally {
            $this->reading = false;
        }

        return self::toValues($columns, is_array($row) ? $row : []);
    }

    /**
     * @param list<string> $columns
     * @param array<string, mixed> $row
     *
     * @return array<string, FieldValue>
     */
    private static function toValues(array $columns, array $row): array
    {
        $values = [];

        foreach ($columns as $column) {
            $raw = $row[$column] ?? null;

            if ($raw === null || $raw === '' || (in_array($column, self::FLAGS, true) && (string) $raw === self::flagDefault($column))) {
                $values[$column] = FieldValue::missing();
            } else {
                $values[$column] = FieldValue::of((string) $raw);
            }
        }

        return $values;
    }

    /** A flag at its default is the same as no row: AIOSEO creates rows with it. */
    private static function flagDefault(string $column): string
    {
        return $column === 'robots_default' ? '1' : '0';
    }

    /**
     * Tracked columns this version of AIOSEO has.
     *
     * @return list<string>
     */
    private function columns(): array
    {
        if ($this->columns === null) {
            $this->reading = true;

            try {
                $existing = array_map('strval', (array) $this->db->get_col($this->db->prepare("SHOW COLUMNS FROM `{$this->table()}` LIKE %s", '%')));
            } finally {
                $this->reading = false;
            }

            $this->columns = array_values(array_intersect(array_keys(self::labels()), $existing));
        }

        return $this->columns;
    }

    private function table(): string
    {
        return $this->db->prefix . 'aioseo_posts';
    }

    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'title' => __('SEO title', 'selective-undo'),
            'description' => __('Meta description', 'selective-undo'),
            'focus_keyword' => __('Focus keyphrase', 'selective-undo'),
            'canonical_url' => __('Canonical URL', 'selective-undo'),
            'og_title' => __('Social title', 'selective-undo'),
            'og_description' => __('Social description', 'selective-undo'),
            'twitter_title' => __('X (Twitter) title', 'selective-undo'),
            'twitter_description' => __('X (Twitter) description', 'selective-undo'),
            'robots_default' => __('Default robots settings', 'selective-undo'),
            'robots_noindex' => __('Hide from search engines (noindex)', 'selective-undo'),
            'robots_nofollow' => __('Do not follow links (nofollow)', 'selective-undo'),
        ];
    }
}
