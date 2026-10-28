<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterCapabilities;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\DescribesFields;
use SelectiveUndo\Domain\Contracts\FieldDefinition;
use SelectiveUndo\Domain\Contracts\MergeableAdapter;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Contracts\WallClock;
use SelectiveUndo\Domain\Merge\MergeStrategy;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;

/**
 * Standard wp_posts fields. The field list is hard-coded and never extended from
 * request data. Writes go through a direct, whitelisted UPDATE:
 * wp_update_post() would run arbitrary save hooks inside the restore.
 */
final class PostFieldsAdapter implements AdapterInterface, DescribesFields, MergeableAdapter, PresentsValues
{
    public const KEY = 'core/post-fields';
    /** 2: slug, status, date, author, parent and discussion; the format of version 1 fields is unchanged. */
    public const VERSION = 2;

    public const FIELDS = PostRow::TRACKED_FIELDS;

    /** Integer columns. */
    private const INT_FIELDS = ['menu_order', 'post_author', 'post_parent'];

    /** Statuses a restore never sets: the trash and creation have their own way back. */
    private const NEVER_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /** kses contexts WordPress applies to these fields for users without unfiltered_html. */
    private const KSES_CONTEXT = [
        'post_title' => 'title_save_pre',
        'post_content' => 'post',
        'post_excerpt' => 'post',
    ];

    /** Meta keys that identify page builders (extendable via selective_undo/page_builder_markers). */
    private const PAGE_BUILDER_MARKERS = [
        '_elementor_edit_mode' => 'elementor',
        '_fl_builder_enabled' => 'beaver_builder',
        '_bricks_page_content_2' => 'bricks',
        'ct_builder_json' => 'oxygen',
        '_et_pb_use_builder' => 'divi',
    ];

    public function __construct(
        private readonly string $postsTable,
        private readonly TrackingPolicy $policy,
        private readonly WallClock $clock,
        private readonly PostRowReader $reader,
        private readonly CacheInvalidator $caches,
        private readonly string $postmetaTable,
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
        return $version === 1 || $version === self::VERSION;
    }

    public function supports(ObjectRef $object, string $fieldKey): bool
    {
        return $object->type === 'post'
            && in_array($fieldKey, self::FIELDS, true)
            && $this->policy->isTrackedPostType($object->subtype);
    }

    public function fieldLabel(string $fieldKey): string
    {
        return match ($fieldKey) {
            'post_title' => __('Title', 'selective-undo'),
            'post_content' => __('Content', 'selective-undo'),
            'post_excerpt' => __('Excerpt', 'selective-undo'),
            'menu_order' => _x('Order', 'post menu order field', 'selective-undo'),
            'post_name' => __('Slug', 'selective-undo'),
            'post_status' => __('Status', 'selective-undo'),
            'post_date' => __('Publish date', 'selective-undo'),
            'post_author' => __('Author', 'selective-undo'),
            'post_parent' => __('Parent', 'selective-undo'),
            'comment_status' => __('Comments', 'selective-undo'),
            'ping_status' => __('Pingbacks and trackbacks', 'selective-undo'),
            default => $fieldKey,
        };
    }

    /**
     * The fields with the names the item's own screens use: for a media file the
     * excerpt is its caption and the content its description.
     */
    public function fields(ObjectRef $object): array
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            $label = match (true) {
                $object->subtype === 'attachment' && $field === 'post_excerpt' => __('Caption', 'selective-undo'),
                $object->subtype === 'attachment' && $field === 'post_content' => __('Description', 'selective-undo'),
                default => $this->fieldLabel($field),
            };
            $fields[] = new FieldDefinition($field, $label, in_array($field, self::INT_FIELDS, true) ? 'int' : 'text', $this->mergeStrategy($field));
        }

        return $fields;
    }

    /** Content is undone block by block; the other fields as a whole. */
    public function mergeStrategy(string $fieldKey): MergeStrategy
    {
        return $fieldKey === 'post_content' ? MergeStrategy::Segments : MergeStrategy::Replace;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            fields: self::FIELDS,
            requiresTransactions: true,
            supportsBackground: true,
            hasLinkedData: false,
            knownSideEffects: ['post_modified', 'object_cache', 'page_cache', 'wp_revision', 'old_slug_redirect', 'scheduled_publish', 'term_counts'],
            idempotent: true,
            maxValueBytes: null,
        );
    }

    public function read(ObjectRef $object): ?PostRow
    {
        return $this->reader->read($object->id);
    }

    public function lockAndRead(RestoreTransaction $tx, ObjectRef $object): ?PostRow
    {
        $rows = $tx->select(
            sprintf('SELECT %s FROM `%s` WHERE ID = ? FOR UPDATE', PostRow::COLUMNS, $this->postsTable),
            'i',
            [$object->id]
        );

        return $rows === [] ? null : PostRow::fromDb($rows[0]);
    }

    public function authorize(int $userId, ObjectRef $object, string $action): bool
    {
        $cap = $action === 'view' ? Capabilities::VIEW_OBJECT : Capabilities::RESTORE_OBJECT;

        return user_can($userId, $cap, $object->id, $object->subtype);
    }

    public function validateState(ObjectSnapshot $current, string $fieldKey): ?string
    {
        assert($current instanceof PostRow);

        return match (true) {
            !in_array($fieldKey, self::FIELDS, true) => 'field_not_supported',
            !$this->policy->isTrackedPostType($current->postType) => 'post_type_not_tracked',
            $current->postStatus === 'trash' => 'object_trashed',
            default => null,
        };
    }

    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string
    {
        $value = $target->value;

        return match ($fieldKey) {
            'menu_order' => is_int($value) ? null : 'target_type_mismatch',
            'post_name' => is_string($value) ? $this->checkSlug($object, $value) : 'target_type_mismatch',
            'post_status' => is_string($value) ? $this->checkStatus($actorUserId, $object, $value) : 'target_type_mismatch',
            'post_date' => self::dates($target) !== null ? null : 'target_type_mismatch',
            'post_author' => is_int($value) ? $this->checkAuthor($actorUserId, $object, $value) : 'target_type_mismatch',
            'post_parent' => is_int($value) ? $this->checkParent($object, $value) : 'target_type_mismatch',
            'comment_status', 'ping_status' => in_array($value, ['open', 'closed'], true) ? null : 'target_type_mismatch',
            default => $this->checkMarkup($actorUserId, $fieldKey, $value),
        };
    }

    private function checkMarkup(int $actorUserId, string $fieldKey, mixed $value): ?string
    {
        if (!isset(self::KSES_CONTEXT[$fieldKey]) || !is_string($value)) {
            return 'target_type_mismatch';
        }

        // A direct SQL write bypasses kses. A user without unfiltered_html must not
        // bring back markup WordPress would not let them save. kses normalisation
        // may cause false positives; that is a safe refusal.
        if (!user_can($actorUserId, 'unfiltered_html') && wp_kses($value, self::KSES_CONTEXT[$fieldKey]) !== $value) {
            return 'requires_unfiltered_html';
        }

        return null;
    }

    /** The slug must still be free: WordPress would otherwise give the post another one. */
    private function checkSlug(ObjectRef $object, string $slug): ?string
    {
        $post = get_post($object->id);

        if (!$post instanceof \WP_Post || $slug === '') {
            return null;
        }

        return wp_unique_post_slug($slug, $post->ID, $post->post_status, $post->post_type, $post->post_parent) === $slug ? null : 'slug_taken';
    }

    private function checkStatus(int $actorUserId, ObjectRef $object, string $status): ?string
    {
        if (in_array($status, self::NEVER_STATUSES, true) || get_post_status_object($status) === null) {
            return 'status_not_restorable';
        }

        $type = get_post_type_object($object->subtype);

        if (in_array($status, ['publish', 'future', 'private'], true) && ($type === null || !user_can($actorUserId, $type->cap->publish_posts))) {
            return 'publish_not_allowed';
        }

        return null;
    }

    private function checkAuthor(int $actorUserId, ObjectRef $object, int $authorId): ?string
    {
        $type = get_post_type_object($object->subtype);

        if ($type === null) {
            return 'author_invalid';
        }

        // 0 is "no author": WordPress creates such posts itself (WP-CLI, imports, cron).
        $author = $authorId === 0 ? null : get_userdata($authorId);

        if ($authorId !== 0 && (!$author instanceof \WP_User || !user_can($author, $type->cap->edit_posts))) {
            return 'author_invalid';
        }

        return user_can($actorUserId, $type->cap->edit_others_posts) ? null : 'author_not_allowed';
    }

    /** The parent exists, has the same type and is not the post or one of its descendants. */
    private function checkParent(ObjectRef $object, int $parentId): ?string
    {
        if ($parentId === 0) {
            return null;
        }

        $parent = get_post($parentId);

        if (!$parent instanceof \WP_Post || $parent->post_type !== $object->subtype) {
            return 'parent_invalid';
        }

        for ($id = $parentId, $depth = 0; $id > 0 && $depth < 100; $depth++) {
            if ($id === $object->id) {
                return 'parent_invalid';
            }

            $id = (int) get_post_field('post_parent', $id, 'raw');
        }

        return null;
    }

    public function warnings(ObjectSnapshot $current, array $fieldKeys, int $viewerUserId): array
    {
        assert($current instanceof PostRow);
        $warnings = [];

        if (in_array('post_status', $fieldKeys, true)) {
            $warnings[] = 'status_change_visible';
        }

        // Another user has the post open in an editor: saving their stale form
        // would overwrite the restored values.
        $lock = (string) get_post_meta($current->id, '_edit_lock', true);
        $parts = array_map('intval', explode(':', $lock) + [0, 0]);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied with the same default as wp_check_post_lock().
        $window = (int) apply_filters('wp_check_post_lock_window', 150);

        if (($parts[1] ?? 0) > 0 && $parts[1] !== $viewerUserId && $parts[0] > time() - $window) {
            $warnings[] = 'post_locked';
        }

        if (in_array('post_content', $fieldKeys, true)) {
            $markers = (array) apply_filters('selective_undo/page_builder_markers', self::PAGE_BUILDER_MARKERS);

            foreach (array_keys($markers) as $metaKey) {
                $value = get_post_meta($current->id, (string) $metaKey, true);

                if ($value !== '' && $value !== false && $value !== 'no' && $value !== 'off') {
                    $warnings[] = 'page_builder_detected';
                    break;
                }
            }

            // For example Jetpack Markdown keeps the source in post_content_filtered and
            // would overwrite the restored post_content on the next edit.
            if (!in_array(get_post_field('post_content_filtered', $current->id, 'raw'), ['', null], true)) {
                $warnings[] = 'linked_field_present';
            }
        }

        return $warnings;
    }

    public function write(RestoreTransaction $tx, ObjectSnapshot $current, array $values): void
    {
        assert($current instanceof PostRow);
        $sets = [];
        $types = '';
        $params = [];

        foreach ($values as $field => $value) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new \LogicException('Field is not whitelisted.');
            }

            if ($field === 'post_date') {
                // validateTarget() already rejected other shapes; this guards the SQL types.
                $dates = self::dates($value) ?? throw new \LogicException('Target type does not match the field.');
                array_push($sets, '`post_date` = ?', '`post_date_gmt` = ?');
                array_push($params, $dates['local'], $dates['gmt']);
                $types .= 'ss';
                continue;
            }

            $isInt = in_array($field, self::INT_FIELDS, true);
            $sets[] = sprintf('`%s` = ?', $field);
            $params[] = match (true) {
                $isInt && is_int($value->value) => $value->value,
                !$isInt && is_string($value->value) => $value->value,
                default => throw new \LogicException('Target type does not match the field.'),
            };
            $types .= $isInt ? 'i' : 's';
        }

        if ($sets === []) {
            return;
        }

        // post_modified is the restore time, never a historical date. guid is never touched.
        $sets[] = 'post_modified = ?';
        $sets[] = 'post_modified_gmt = ?';
        $types .= 'ssi';
        $params[] = $this->clock->siteLocalMysql();
        $params[] = $this->clock->utcMysql();
        $params[] = $current->id;

        $affected = $tx->write(
            sprintf('UPDATE `%s` SET %s WHERE ID = ?', $this->postsTable, implode(', ', $sets)),
            $types,
            $params
        );

        if ($affected !== 1) {
            throw new StorageError('post_update_affected_' . $affected);
        }

        $slug = $values['post_name']->value ?? null;

        if (is_string($slug) && $current->postName !== '' && $current->postName !== $slug && $current->postStatus === 'publish') {
            // As a normal save does: links to the old address keep redirecting.
            $this->rememberOldSlug($tx, $current->id, $current->postName, $slug);
        }
    }

    private function rememberOldSlug(RestoreTransaction $tx, int $postId, string $old, string $new): void
    {
        $tx->write(
            "DELETE FROM `{$this->postmetaTable}` WHERE post_id = ? AND meta_key = '_wp_old_slug' AND meta_value = ?",
            'is',
            [$postId, $new]
        );
        $known = $tx->select(
            "SELECT meta_id FROM `{$this->postmetaTable}` WHERE post_id = ? AND meta_key = '_wp_old_slug' AND meta_value = ?",
            'is',
            [$postId, $old]
        );

        if ($known === []) {
            $tx->write("INSERT INTO `{$this->postmetaTable}` (post_id, meta_key, meta_value) VALUES (?, '_wp_old_slug', ?)", 'is', [$postId, $old]);
        }
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        $this->caches->purgePost($object->id);
        wp_cache_delete($object->id, 'post_meta');

        if (array_intersect($fieldKeys, ['post_status', 'post_date']) !== []) {
            $post = get_post($object->id);

            if ($post instanceof \WP_Post) {
                // A scheduled post needs its publishing event, any other none.
                wp_clear_scheduled_hook('publish_future_post', [$post->ID]);

                if ($post->post_status === 'future') {
                    wp_schedule_single_event((int) strtotime($post->post_date_gmt . ' GMT'), 'publish_future_post', [$post->ID]);
                }
            }
        }

        if (in_array('post_status', $fieldKeys, true)) {
            // Term counts include published posts only.
            $type = $object->subtype !== '' ? $object->subtype : (string) get_post_type($object->id);

            foreach (get_object_taxonomies($type) as $taxonomy) {
                $ttIds = wp_get_object_terms($object->id, $taxonomy, ['fields' => 'tt_ids']);

                if (is_array($ttIds) && $ttIds !== []) {
                    wp_update_term_count_now(array_map('intval', $ttIds), $taxonomy);
                }
            }
        }
    }

    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string
    {
        $v = $value->value;

        return match (true) {
            $fieldKey === 'post_status' && is_string($v) => (string) (get_post_status_object($v)->label ?? $v),
            $fieldKey === 'post_date' => self::dates($value)['local'] ?? '',
            $fieldKey === 'post_author' && is_int($v) => self::userName($v),
            $fieldKey === 'post_parent' && is_int($v) => $v === 0 ? '' : get_the_title($v) . ' (#' . $v . ')',
            in_array($fieldKey, ['comment_status', 'ping_status'], true) => $v === 'open' ? __('Open', 'selective-undo') : __('Closed', 'selective-undo'),
            default => $value->toDisplayString(),
        };
    }

    private static function userName(int $userId): string
    {
        $user = $userId > 0 ? get_userdata($userId) : false;

        return $user instanceof \WP_User ? $user->display_name : ($userId > 0 ? '#' . $userId : '');
    }

    /**
     * @return array{local: string, gmt: string}|null
     */
    private static function dates(FieldValue $value): ?array
    {
        $v = $value->value;
        $format = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

        if (!is_array($v) || !is_string($v['local'] ?? null) || !is_string($v['gmt'] ?? null)
            || preg_match($format, $v['local']) !== 1 || preg_match($format, $v['gmt']) !== 1) {
            return null;
        }

        return ['local' => $v['local'], 'gmt' => $v['gmt']];
    }
}
