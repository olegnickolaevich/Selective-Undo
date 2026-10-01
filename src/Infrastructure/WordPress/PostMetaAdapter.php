<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Application\Capture\RequestContext;
use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterCapabilities;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\CaptureSink;
use SelectiveUndo\Domain\Contracts\CapturingAdapter;
use SelectiveUndo\Domain\Contracts\DescribesFields;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\PresentsImages;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;

/**
 * Registered post meta keys ("meta:<key>", see MetaFieldRegistry). Values are the
 * raw meta_value strings: one string for a single row, a list of strings (meta_id
 * order) for several rows, missing when there is none. They are stored and written
 * back byte for byte; nothing is ever unserialized on the restore path.
 */
final class PostMetaAdapter implements AdapterInterface, CapturingAdapter, DescribesFields, PresentsValues, PresentsImages
{
    public const KEY = 'core/post-meta';
    public const VERSION = 1;

    /** @var array<string, FieldValue> "postId|key" => value read right before a write */
    private array $pending = [];

    public function __construct(
        private readonly \wpdb $db,
        private readonly MetaFieldRegistry $registry,
        private readonly TrackingPolicy $policy,
        private readonly RequestContext $request,
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
            fields: [],
            requiresTransactions: true,
            supportsBackground: true,
            hasLinkedData: false,
            knownSideEffects: ['object_cache', 'page_cache', 'updated_post_meta'],
            idempotent: true,
            maxValueBytes: null,
        );
    }

    public function supports(ObjectRef $object, string $fieldKey): bool
    {
        return $object->type === 'post' && str_starts_with($fieldKey, 'meta:')
            && $this->policy->isTrackedPostType($object->subtype)
            && $this->registry->isTracked($object->subtype, substr($fieldKey, 5));
    }

    public function fieldLabel(string $fieldKey): string
    {
        if (!str_starts_with($fieldKey, 'meta:')) {
            return $fieldKey;
        }

        $key = substr($fieldKey, 5);

        foreach ($this->policy->trackedPostTypes() as $type) {
            $definition = $this->registry->definition($type, $key);

            if ($definition !== null) {
                return $definition->group !== null ? $definition->group . ': ' . $definition->label : $definition->label;
            }
        }

        return $key;
    }

    public function fields(ObjectRef $object): array
    {
        return $this->registry->definitions($object->subtype);
    }

    public function registerCapture(CaptureSink $sink): void
    {
        $before = function (mixed $check, mixed $postId, mixed $metaKey) use ($sink): mixed {
            // Pre-filters run right before the write: remember the value it replaces.
            if ($check === null && $sink->isActive() && $this->watched((int) $postId, (string) $metaKey)) {
                $this->pending[(int) $postId . '|' . $metaKey] ??= $this->readValue((int) $postId, (string) $metaKey);
            }

            return $check;
        };

        add_filter('update_post_metadata', $before, PHP_INT_MAX, 3);
        add_filter('add_post_metadata', $before, PHP_INT_MAX, 3);
        add_filter('delete_post_metadata', $before, PHP_INT_MAX, 3);

        $after = function (mixed $metaIds, mixed $postId, mixed $metaKey) use ($sink): void {
            $slot = (int) $postId . '|' . $metaKey;

            if (!isset($this->pending[$slot])) {
                return;
            }

            $before = $this->pending[$slot];
            unset($this->pending[$slot]);
            $now = $this->readValue((int) $postId, (string) $metaKey);

            if (!$before->equals($now)) {
                $sink->record($this, new ObjectRef('post', (int) $postId, (string) get_post_type((int) $postId)), [
                    'meta:' . $metaKey => [$before, $now],
                ], CaptureQuality::Verified);
            }
        };

        add_action('updated_post_meta', $after, PHP_INT_MAX, 3);
        add_action('added_post_meta', $after, PHP_INT_MAX, 3);
        add_action('deleted_post_meta', $after, PHP_INT_MAX, 3);
    }

    public function read(ObjectRef $object): ?ObjectSnapshot
    {
        $post = $this->db->get_row($this->db->prepare("SELECT post_type, post_status FROM {$this->db->posts} WHERE ID = %d", $object->id), ARRAY_A);

        if (!is_array($post)) {
            return null;
        }

        return new FieldsSnapshot(
            $object->id,
            (string) $post['post_type'],
            fn (string $field): FieldValue => $this->readValue($object->id, substr($field, 5)),
            (string) $post['post_status']
        );
    }

    public function lockAndRead(RestoreTransaction $tx, ObjectRef $object): ?ObjectSnapshot
    {
        $post = $tx->select("SELECT post_type, post_status FROM `{$this->db->posts}` WHERE ID = ?", 'i', [$object->id]);

        if ($post === []) {
            return null;
        }

        // The post row is locked first (ObjectLock); then the meta rows we may touch.
        $rows = $tx->select(
            "SELECT meta_key, meta_value FROM `{$this->db->postmeta}` WHERE post_id = ? ORDER BY meta_id FOR UPDATE",
            'i',
            [$object->id]
        );
        $byKey = [];

        foreach ($rows as $row) {
            $byKey[(string) $row['meta_key']][] = (string) $row['meta_value'];
        }

        return new FieldsSnapshot(
            $object->id,
            (string) $post[0]['post_type'],
            static fn (string $field): FieldValue => self::toValue($byKey[substr($field, 5)] ?? []),
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
            !str_starts_with($fieldKey, 'meta:') || in_array(substr($fieldKey, 5), MetaFieldRegistry::NEVER, true) => 'field_not_supported',
            !$this->policy->isTrackedPostType($current->objectSubtype()) => 'post_type_not_tracked',
            $current->status === 'trash' => 'object_trashed',
            default => null,
        };
    }

    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string
    {
        $key = substr($fieldKey, 5);
        $rows = self::toRows($target);

        if ($rows === null) {
            return 'target_type_mismatch';
        }

        // Keys a user may edit in the Custom Fields box need that permission; the
        // registered protected keys (featured image, SEO, ACF) the restore permission.
        if (!is_protected_meta($key, 'post') && !user_can($actorUserId, 'edit_post_meta', $object->id, $key)) {
            return 'meta_not_allowed';
        }

        if ($key === '_thumbnail_id' && $rows !== [] && get_post_type((int) $rows[0]) !== 'attachment') {
            return 'attachment_missing';
        }

        // A direct write bypasses sanitize_meta and kses: no markup the user could not save.
        if (!user_can($actorUserId, 'unfiltered_html')) {
            foreach ($rows as $raw) {
                if (wp_kses_post($raw) !== $raw) {
                    return 'requires_unfiltered_html';
                }
            }
        }

        return null;
    }

    public function warnings(ObjectSnapshot $current, array $fieldKeys, int $viewerUserId): array
    {
        return [];
    }

    public function write(RestoreTransaction $tx, ObjectSnapshot $current, array $values): void
    {
        foreach ($values as $field => $value) {
            $key = substr($field, 5);
            $rows = self::toRows($value);

            if (!str_starts_with($field, 'meta:') || in_array($key, MetaFieldRegistry::NEVER, true) || $rows === null) {
                throw new \LogicException('Meta key is not restorable.');
            }

            $tx->write("DELETE FROM `{$this->db->postmeta}` WHERE post_id = ? AND meta_key = ?", 'is', [$current->objectId(), $key]);

            foreach ($rows as $raw) {
                if ($tx->write("INSERT INTO `{$this->db->postmeta}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)", 'iss', [$current->objectId(), $key, $raw]) !== 1) {
                    throw new StorageError('meta_insert_failed');
                }
            }
        }
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        wp_cache_delete($object->id, 'post_meta');
        $this->caches->purgePost($object->id);

        // Lets caches and SEO indexes of other plugins refresh, without recording history.
        $this->request->whileRestoring(function () use ($object, $fieldKeys): void {
            foreach ($fieldKeys as $field) {
                $key = substr($field, 5);
                $metaId = (int) $this->db->get_var($this->db->prepare(
                    "SELECT meta_id FROM {$this->db->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1",
                    $object->id,
                    $key
                ));

                if ($metaId > 0) {
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action, fired as update_post_meta() would.
                    do_action('updated_post_meta', $metaId, $object->id, $key, get_post_meta($object->id, $key, true));
                } else {
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action, fired as delete_post_meta() would.
                    do_action('deleted_post_meta', [], $object->id, $key, '');
                }
            }
        });
    }

    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string
    {
        if (!$value->exists) {
            return '';
        }

        $rows = self::toRows($value) ?? [];

        if ($this->isAttachment($object, $fieldKey)) {
            return implode("\n", array_map(static function (string $id): string {
                $file = get_attached_file((int) $id);

                return $file !== false ? wp_basename($file) . ' (#' . $id . ')' : '#' . $id;
            }, $rows));
        }

        return implode("\n", array_map(static function (string $raw): string {
            if (!is_serialized($raw)) {
                return $raw;
            }

            // Shown only: never instantiate objects from stored data.
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes=false.
            $data = @unserialize($raw, ['allowed_classes' => false]);

            return $data === false && $raw !== 'b:0;' ? $raw : (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $rows));
    }

    public function imageUrl(ObjectRef $object, string $fieldKey, FieldValue $value): ?string
    {
        $rows = self::toRows($value) ?? [];

        if (count($rows) !== 1 || !ctype_digit($rows[0]) || !$this->isAttachment($object, $fieldKey) || !wp_attachment_is_image((int) $rows[0])) {
            return null;
        }

        $url = wp_get_attachment_image_url((int) $rows[0], 'thumbnail');

        return is_string($url) ? $url : null;
    }

    private function isAttachment(ObjectRef $object, string $fieldKey): bool
    {
        $key = substr($fieldKey, 5);

        return $key === '_thumbnail_id' || $this->registry->definition($object->subtype, $key)?->valueType === 'attachment';
    }

    private function watched(int $postId, string $key): bool
    {
        if ($postId <= 0 || in_array($key, MetaFieldRegistry::NEVER, true) || $this->request->isRestoring() || $this->request->inPostLifecycle()) {
            return false;
        }

        $post = get_post($postId);

        return $post instanceof \WP_Post && $post->post_status !== 'auto-draft'
            && $this->policy->shouldCapture($postId, $post->post_type)
            && $this->registry->isTracked($post->post_type, $key);
    }

    private function readValue(int $postId, string $key): FieldValue
    {
        return self::toValue(array_values(array_map('strval', (array) $this->db->get_col($this->db->prepare(
            "SELECT meta_value FROM {$this->db->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id",
            $postId,
            $key
        )))));
    }

    /**
     * @param list<string> $rows
     */
    private static function toValue(array $rows): FieldValue
    {
        return match (count($rows)) {
            0 => FieldValue::missing(),
            1 => FieldValue::of($rows[0]),
            default => FieldValue::of($rows),
        };
    }

    /**
     * @return list<string>|null Null for a value this adapter never produces.
     */
    private static function toRows(FieldValue $value): ?array
    {
        if (!$value->exists) {
            return [];
        }

        if (is_string($value->value)) {
            return [$value->value];
        }

        if (is_array($value->value) && array_is_list($value->value) && array_filter($value->value, 'is_string') === $value->value) {
            return $value->value;
        }

        return null;
    }
}
