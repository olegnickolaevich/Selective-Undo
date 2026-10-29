<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterCapabilities;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\DescribesFields;
use SelectiveUndo\Domain\Contracts\FieldDefinition;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;

/**
 * A permanently deleted post as one value ("snapshot"): its row, its meta rows in
 * order and its terms, taken right before WordPress deletes them. Restoring puts
 * the post back under its own ID as a draft (as WordPress does when it takes a post
 * out of the trash); the status it had is kept in a meta row, so that the restored
 * post reads back as exactly the value that was written.
 *
 * A post that exists under the ID is never overwritten: it is not the deleted value,
 * so the plan reports the ID as taken.
 *
 * Not restored: comments, revisions, child posts and media files. Media are not
 * covered at all: their files are gone with them.
 */
final class PostDeletionAdapter implements AdapterInterface, DescribesFields, PresentsValues
{
    public const KEY = 'core/post-deletion';
    public const VERSION = 1;
    public const FIELD = 'snapshot';
    /** The status before the deletion of a post restored as a draft. */
    public const STATUS_META = '_selective_undo_deleted_status';

    /** Columns written back; others take their defaults (comment_count: comments are not restored). */
    private const COLUMNS = [
        'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status',
        'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified',
        'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type',
    ];

    /**
     * Meta that describes the deletion or an editing session, not the post; and the flags
     * that make WordPress send pingbacks and fetch enclosures, an effect outside the site.
     */
    private const SKIPPED_META = ['_edit_lock', '_pingme', '_encloseme', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug', self::STATUS_META];

    public function __construct(
        private readonly \wpdb $db,
        private readonly TrackingPolicy $policy,
        private readonly CacheInvalidator $caches,
    ) {
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
            fields: [self::FIELD],
            requiresTransactions: true,
            supportsBackground: true,
            hasLinkedData: true,
            knownSideEffects: ['term_counts', 'object_cache', 'page_cache'],
            idempotent: true,
            maxValueBytes: null,
        );
    }

    public function supports(ObjectRef $object, string $fieldKey): bool
    {
        return $object->type === 'post' && $fieldKey === self::FIELD && $object->subtype !== 'attachment'
            && $this->policy->isTrackedPostType($object->subtype);
    }

    public function fieldLabel(string $fieldKey): string
    {
        return $fieldKey === self::FIELD ? __('Whole item', 'selective-undo') : $fieldKey;
    }

    public function fields(ObjectRef $object): array
    {
        return [new FieldDefinition(self::FIELD, __('Whole item', 'selective-undo'), 'raw')];
    }

    /**
     * The value of a post about to be deleted, read from the database.
     */
    public function capture(int $postId): FieldValue
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->db->posts} WHERE ID = %d", $postId), ARRAY_A);

        if (!is_array($row)) {
            return FieldValue::missing();
        }

        $meta = (array) $this->db->get_results($this->db->prepare(
            "SELECT meta_key, meta_value FROM {$this->db->postmeta} WHERE post_id = %d ORDER BY meta_id",
            $postId
        ), ARRAY_A);
        $terms = (array) $this->db->get_results($this->db->prepare(
            "SELECT tt.taxonomy, tt.term_id, tr.term_order FROM {$this->db->term_relationships} tr
             JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d ORDER BY tt.taxonomy, tt.term_id",
            $postId
        ), ARRAY_A);

        return self::snapshot($row, $meta, $terms);
    }

    public function read(ObjectRef $object): ObjectSnapshot
    {
        $type = $this->db->get_var($this->db->prepare("SELECT post_type FROM {$this->db->posts} WHERE ID = %d", $object->id));
        $exists = $type !== null;

        return new FieldsSnapshot(
            $object->id,
            $exists ? (string) $type : $object->subtype,
            fn (): FieldValue => $exists ? $this->capture($object->id) : FieldValue::missing(),
            $exists ? 'exists' : ''
        );
    }

    public function lockAndRead(RestoreTransaction $tx, ObjectRef $object): ObjectSnapshot
    {
        // The post row (or the gap where it was) is locked by ObjectLock.
        $row = $tx->select("SELECT * FROM `{$this->db->posts}` WHERE ID = ?", 'i', [$object->id]);

        if ($row === []) {
            return new FieldsSnapshot($object->id, $object->subtype, static fn (): FieldValue => FieldValue::missing());
        }

        $meta = $tx->select("SELECT meta_key, meta_value FROM `{$this->db->postmeta}` WHERE post_id = ? ORDER BY meta_id FOR UPDATE", 'i', [$object->id]);
        $terms = $tx->select(
            "SELECT tt.taxonomy, tt.term_id, tr.term_order FROM `{$this->db->term_relationships}` tr
             JOIN `{$this->db->term_taxonomy}` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = ? ORDER BY tt.taxonomy, tt.term_id",
            'i',
            [$object->id]
        );
        $value = self::snapshot($row[0], $meta, $terms);

        return new FieldsSnapshot($object->id, (string) $row[0]['post_type'], static fn (): FieldValue => $value, 'exists');
    }

    public function authorize(int $userId, ObjectRef $object, string $action): bool
    {
        if (get_post($object->id) instanceof \WP_Post) {
            return user_can($userId, $action === 'view' ? Capabilities::VIEW_OBJECT : Capabilities::RESTORE_OBJECT, $object->id, $object->subtype);
        }

        if ($action === 'view') {
            return user_can($userId, Capabilities::VIEW_OBJECT, $object->id, $object->subtype);
        }

        // A deleted post comes back as a draft: whoever may edit others' posts of its type.
        $type = get_post_type_object($object->subtype);

        return $type !== null && user_can($userId, Capabilities::RESTORE_CHANGES) && user_can($userId, (string) $type->cap->edit_others_posts);
    }

    public function validateState(ObjectSnapshot $current, string $fieldKey): ?string
    {
        assert($current instanceof FieldsSnapshot);

        return match (true) {
            $fieldKey !== self::FIELD => 'field_not_supported',
            !$this->policy->isTrackedPostType($current->objectSubtype()) => 'post_type_not_tracked',
            default => null,
        };
    }

    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string
    {
        if (!$target->exists) {
            // Undoing the restore of a deleted post would delete it again: use the trash instead.
            return 'deletion_not_restorable';
        }

        $post = self::post($target);

        if ($post === null) {
            return 'target_type_mismatch';
        }

        if (!user_can($actorUserId, 'unfiltered_html')) {
            foreach (['post_title' => 'title_save_pre', 'post_content' => 'post', 'post_excerpt' => 'post'] as $column => $context) {
                $value = (string) ($post[$column] ?? '');

                if (wp_kses($value, $context) !== $value) {
                    return 'requires_unfiltered_html';
                }
            }
        }

        return null;
    }

    public function warnings(ObjectSnapshot $current, array $fieldKeys, int $viewerUserId): array
    {
        assert($current instanceof FieldsSnapshot);

        // What a restore of a deletion does and does not bring back (FR-8.3.21, FR-8.3.22).
        return $current->status === 'exists' ? [] : ['restored_as_draft'];
    }

    public function write(RestoreTransaction $tx, ObjectSnapshot $current, array $values): void
    {
        $target = $values[self::FIELD] ?? null;
        $post = $target instanceof FieldValue ? self::post($target) : null;

        if ($post === null || !is_array($target?->value)) {
            throw new \LogicException('Only a deleted post can be restored.');
        }

        $row = array_intersect_key($post, array_flip(self::COLUMNS));
        $row['ID'] = (string) $current->objectId();
        $status = (string) ($row['post_status'] ?? 'draft');
        $row['post_status'] = 'draft';
        $columns = array_keys($row);

        if ($tx->write(
            "INSERT INTO `{$this->db->posts}` (`" . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            str_repeat('s', count($columns)),
            array_map(static fn ($v): ?string => $v === null ? null : (string) $v, array_values($row))
        ) !== 1) {
            throw new StorageError('post_insert_failed');
        }

        $meta = (array) ($target->value['meta'] ?? []);
        $meta[] = [self::STATUS_META, $status];

        foreach ($meta as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1])) {
                $tx->write(
                    "INSERT INTO `{$this->db->postmeta}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)",
                    'iss',
                    [$current->objectId(), (string) $pair[0], (string) $pair[1]]
                );
            }
        }

        foreach ((array) ($target->value['terms'] ?? []) as $term) {
            if (!is_array($term) || !isset($term[0], $term[1])) {
                continue;
            }

            // Terms deleted since then are left out.
            $tt = $tx->select(
                "SELECT term_taxonomy_id FROM `{$this->db->term_taxonomy}` WHERE taxonomy = ? AND term_id = ?",
                'si',
                [(string) $term[0], (int) $term[1]]
            );

            if ($tt !== []) {
                $tx->write(
                    "INSERT INTO `{$this->db->term_relationships}` (object_id, term_taxonomy_id, term_order) VALUES (?, ?, ?)",
                    'iii',
                    [$current->objectId(), (int) $tt[0]['term_taxonomy_id'], (int) ($term[2] ?? 0)]
                );
            }
        }
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        $this->caches->purgePost($object->id);
        wp_cache_delete($object->id, 'post_meta');
        $type = (string) get_post_type($object->id);

        foreach (get_object_taxonomies($type) as $taxonomy) {
            wp_cache_delete($object->id, $taxonomy . '_relationships');
            $ttIds = wp_get_object_terms($object->id, $taxonomy, ['fields' => 'tt_ids']);

            if (is_array($ttIds) && $ttIds !== []) {
                wp_update_term_count_now(array_map('intval', $ttIds), $taxonomy);
            }
        }
    }

    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string
    {
        $post = self::post($value);

        if ($post === null || !is_array($value->value)) {
            return '';
        }

        $lines = [
            __('Title', 'selective-undo') . ': ' . (string) ($post['post_title'] ?? ''),
            __('Status', 'selective-undo') . ': ' . (string) ($post['post_status'] ?? ''),
            __('Slug', 'selective-undo') . ': ' . urldecode((string) ($post['post_name'] ?? '')),
            __('Published', 'selective-undo') . ': ' . (string) ($post['post_date'] ?? ''),
        ];

        foreach ((array) ($value->value['terms'] ?? []) as $term) {
            if (is_array($term) && isset($term[0], $term[1])) {
                $name = get_term((int) $term[1], (string) $term[0]);
                $lines[] = (string) $term[0] . ': ' . ($name instanceof \WP_Term ? $name->name : '#' . (string) $term[1]);
            }
        }

        /* translators: %d: number of custom field rows. */
        $lines[] = sprintf(__('Custom fields: %d', 'selective-undo'), count((array) ($value->value['meta'] ?? [])));
        $lines[] = '';
        $lines[] = (string) ($post['post_content'] ?? '');

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>>|array<array-key, mixed> $meta
     * @param list<array<string, mixed>>|array<array-key, mixed> $terms
     */
    private static function snapshot(array $row, array $meta, array $terms): FieldValue
    {
        $post = [];

        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $post[$column] = $row[$column] === null ? null : (string) $row[$column];
            }
        }

        $pairs = [];
        $status = null;
        $trashed = [];

        foreach ($meta as $m) {
            $key = (string) ($m['meta_key'] ?? '');

            if ($key === self::STATUS_META) {
                $status = (string) $m['meta_value'];
            } elseif ($key === '_wp_trash_meta_status' || $key === '_wp_desired_post_slug') {
                $trashed[$key] = (string) $m['meta_value'];
            }

            if (!in_array($key, self::SKIPPED_META, true)) {
                $pairs[] = [$key, (string) ($m['meta_value'] ?? '')];
            }
        }

        // Deleted from the trash: the status and slug it had before (moving to the trash
        // changed them; WordPress keeps the originals in meta).
        if (($post['post_status'] ?? '') === 'trash' && isset($trashed['_wp_trash_meta_status'])) {
            $post['post_status'] = $trashed['_wp_trash_meta_status'];
            $post['post_name'] = $trashed['_wp_desired_post_slug'] ?? preg_replace('/__trashed$/', '', (string) ($post['post_name'] ?? ''));
        }

        // A restored post that is still the draft it came back as reads as what was deleted.
        if ($status !== null && ($post['post_status'] ?? '') === 'draft') {
            $post['post_status'] = $status;
        }

        return FieldValue::of([
            'post' => $post,
            'meta' => $pairs,
            'terms' => array_map(static fn (array $t): array => [(string) $t['taxonomy'], (string) $t['term_id'], (string) $t['term_order']], array_values(array_filter($terms, 'is_array'))),
        ]);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function post(FieldValue $value): ?array
    {
        return $value->exists && is_array($value->value) && is_array($value->value['post'] ?? null) ? $value->value['post'] : null;
    }
}
