<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Integrations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Application\Capture\RequestContext;
use SelectiveUndo\Application\Capture\Source;
use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterCapabilities;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\CaptureSink;
use SelectiveUndo\Domain\Contracts\CapturingAdapter;
use SelectiveUndo\Domain\Contracts\DescribesFields;
use SelectiveUndo\Domain\Contracts\FieldDefinition;
use SelectiveUndo\Domain\Contracts\MergeableAdapter;
use SelectiveUndo\Domain\Contracts\NormalizesValues;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Merge\MergeStrategy;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;
use SelectiveUndo\Infrastructure\WordPress\CacheInvalidator;
use SelectiveUndo\Infrastructure\WordPress\Capabilities;
use SelectiveUndo\Infrastructure\WordPress\FieldsSnapshot;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;

/**
 * Elementor layouts. "elementor:data" is the element tree of _elementor_data as an
 * ElementorTree map, so that an undo keeps later edits of other elements and of
 * other settings of the same element; a value that is not a valid tree stays the
 * raw JSON string (undone as a whole). "elementor:page_settings" is the raw
 * _elementor_page_settings meta value.
 *
 * After a restore the generated CSS and element caches of the post are removed;
 * Elementor builds them again on the next view.
 */
final class ElementorAdapter implements AdapterInterface, CapturingAdapter, DescribesFields, MergeableAdapter, NormalizesValues, PresentsValues
{
    public const KEY = 'integration/elementor';
    public const VERSION = 1;

    private const META = [
        'elementor:data' => '_elementor_data',
        'elementor:page_settings' => '_elementor_page_settings',
    ];

    /** Derived from the layout: removed after a restore so that Elementor rebuilds them. */
    private const CACHES = ['_elementor_css', '_elementor_element_cache', '_elementor_page_assets'];

    /** @var array<string, FieldValue> "postId|field" => value read right before a write */
    private array $pending = [];

    public function __construct(
        private readonly \wpdb $db,
        private readonly TrackingPolicy $policy,
        private readonly RequestContext $request,
        private readonly CacheInvalidator $caches,
    ) {
    }

    public static function available(): bool
    {
        return defined('ELEMENTOR_VERSION');
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

    public function mergeStrategy(string $fieldKey): MergeStrategy
    {
        return $fieldKey === 'elementor:data' ? MergeStrategy::Keys : MergeStrategy::Replace;
    }

    /** The tree written and read again: elements in page order, neighbours recomputed. */
    public function normalize(string $fieldKey, FieldValue $value): FieldValue
    {
        if ($fieldKey !== 'elementor:data' || !is_array($value->value)) {
            return $value;
        }

        $map = ElementorTree::flatten(ElementorTree::build($value->value));

        return $map === null ? $value : FieldValue::of($map);
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            fields: array_keys(self::META),
            requiresTransactions: true,
            supportsBackground: true,
            hasLinkedData: true,
            knownSideEffects: ['object_cache', 'page_cache', 'elementor_css'],
            idempotent: true,
            maxValueBytes: null,
        );
    }

    public function supports(ObjectRef $object, string $fieldKey): bool
    {
        return $object->type === 'post' && isset(self::META[$fieldKey]) && $this->policy->isTrackedPostType($object->subtype);
    }

    public function fieldLabel(string $fieldKey): string
    {
        return match ($fieldKey) {
            'elementor:data' => 'Elementor: ' . __('Layout', 'selective-undo'),
            'elementor:page_settings' => 'Elementor: ' . __('Page settings', 'selective-undo'),
            default => $fieldKey,
        };
    }

    public function fields(ObjectRef $object): array
    {
        return [
            new FieldDefinition('elementor:data', __('Layout', 'selective-undo'), 'json', MergeStrategy::Keys, group: 'Elementor'),
            new FieldDefinition('elementor:page_settings', __('Page settings', 'selective-undo'), 'raw', group: 'Elementor'),
        ];
    }

    public function registerCapture(CaptureSink $sink): void
    {
        $before = function (mixed $check, mixed $postId, mixed $metaKey) use ($sink): mixed {
            $field = array_search($metaKey, self::META, true);

            if ($check === null && $field !== false && $sink->isActive() && $this->watched((int) $postId)) {
                $this->pending[(int) $postId . '|' . $field] ??= $this->readValue((int) $postId, $field);
            }

            return $check;
        };

        add_filter('update_post_metadata', $before, PHP_INT_MAX, 3);
        add_filter('add_post_metadata', $before, PHP_INT_MAX, 3);
        add_filter('delete_post_metadata', $before, PHP_INT_MAX, 3);

        $after = function (mixed $metaIds, mixed $postId, mixed $metaKey) use ($sink): void {
            $field = array_search($metaKey, self::META, true);
            $slot = (int) $postId . '|' . $field;

            if ($field === false || !isset($this->pending[$slot])) {
                return;
            }

            $before = $this->pending[$slot];
            unset($this->pending[$slot]);
            $now = $this->readValue((int) $postId, $field);

            if (!$before->equals($now)) {
                $sink->record($this, new ObjectRef('post', (int) $postId, (string) get_post_type((int) $postId)), [
                    $field => [$before, $now],
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
            fn (string $field): FieldValue => $this->readValue($object->id, $field),
            (string) $post['post_status']
        );
    }

    public function lockAndRead(RestoreTransaction $tx, ObjectRef $object): ?ObjectSnapshot
    {
        $post = $tx->select("SELECT post_type, post_status FROM `{$this->db->posts}` WHERE ID = ?", 'i', [$object->id]);

        if ($post === []) {
            return null;
        }

        $rows = $tx->select(
            "SELECT meta_key, meta_value FROM `{$this->db->postmeta}` WHERE post_id = ? AND meta_key IN (?, ?) ORDER BY meta_id FOR UPDATE",
            'iss',
            [$object->id, ...array_values(self::META)]
        );
        $byKey = [];

        foreach ($rows as $row) {
            $byKey[(string) $row['meta_key']][] = (string) $row['meta_value'];
        }

        return new FieldsSnapshot(
            $object->id,
            (string) $post[0]['post_type'],
            static fn (string $field): FieldValue => self::toValue($field, $byKey[self::META[$field] ?? ''] ?? []),
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
            !isset(self::META[$fieldKey]) => 'field_not_supported',
            !$this->policy->isTrackedPostType($current->objectSubtype()) => 'post_type_not_tracked',
            $current->status === 'trash' => 'object_trashed',
            default => null,
        };
    }

    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string
    {
        $raw = self::toRaw($fieldKey, $target);

        if ($target->exists && $raw === null) {
            return 'target_type_mismatch';
        }

        // Elementor filters the layout with kses for users without unfiltered_html; so does a restore.
        if ($raw !== null && !user_can($actorUserId, 'unfiltered_html')) {
            $decoded = $fieldKey === 'elementor:data' ? json_decode($raw, true) : $raw;

            if (!self::ksesClean($decoded)) {
                return 'requires_unfiltered_html';
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
            $raw = self::toRaw($field, $value);

            if (!isset(self::META[$field]) || ($value->exists && $raw === null)) {
                throw new \LogicException('Elementor value is not restorable.');
            }

            $tx->write("DELETE FROM `{$this->db->postmeta}` WHERE post_id = ? AND meta_key = ?", 'is', [$current->objectId(), self::META[$field]]);

            if ($raw !== null && $tx->write(
                "INSERT INTO `{$this->db->postmeta}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)",
                'iss',
                [$current->objectId(), self::META[$field], $raw]
            ) !== 1) {
                throw new StorageError('meta_insert_failed');
            }
        }

        // Generated from the layout: stale until Elementor rebuilds them.
        foreach (self::CACHES as $metaKey) {
            $tx->write("DELETE FROM `{$this->db->postmeta}` WHERE post_id = ? AND meta_key = ?", 'is', [$current->objectId(), $metaKey]);
        }
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        wp_cache_delete($object->id, 'post_meta');

        $this->request->whileRestoring(static function () use ($object): void {
            if (class_exists(\Elementor\Core\Files\CSS\Post::class)) {
                // The CSS file of this post only; the files of other pages stay.
                \Elementor\Core\Files\CSS\Post::create($object->id)->delete();
            } elseif (class_exists(\Elementor\Plugin::class) && isset(\Elementor\Plugin::$instance->files_manager)) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }
        });

        // Elementor keeps a text copy of the layout in post_content (search, excerpts, SEO
        // descriptions) and refreshes it on each save. It is refreshed the same way here,
        // as an ordinary recorded edit; a restore that also brought back post_content
        // leaves nothing to change.
        if (in_array('elementor:data', $fieldKeys, true) && class_exists(\Elementor\Plugin::class) && isset(\Elementor\Plugin::$instance->db)) {
            $source = $this->request->source();
            $this->request->setSource(Source::Restore);

            try {
                \Elementor\Plugin::$instance->db->save_plain_text($object->id);
            } finally {
                $this->request->setSource($source);
            }
        }

        $this->caches->purgePost($object->id);
    }

    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string
    {
        if (!$value->exists) {
            return '';
        }

        if ($fieldKey === 'elementor:data' && is_array($value->value)) {
            return self::outline(ElementorTree::build($value->value), 0);
        }

        $raw = self::toRaw($fieldKey, $value) ?? '';

        if (!is_serialized($raw)) {
            return $raw;
        }

        // Shown only: never instantiate objects from stored data.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes=false.
        $data = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($data) ? (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw;
    }

    /**
     * One line per element and per text setting, indented by depth: readable
     * comparisons instead of one long JSON line.
     *
     * @param list<array<array-key, mixed>> $elements
     */
    private static function outline(array $elements, int $depth): string
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);

        foreach ($elements as $element) {
            $type = (string) ($element['widgetType'] ?? $element['elType'] ?? '');
            $lines[] = $indent . $type . ' #' . (string) ($element['id'] ?? '');

            foreach ((array) ($element['settings'] ?? []) as $name => $setting) {
                if (is_string($setting) && $setting !== '') {
                    $lines[] = $indent . '  ' . $name . ': ' . str_replace("\n", ' ', $setting);
                } elseif (is_array($setting) && is_string($setting['url'] ?? null) && $setting['url'] !== '') {
                    $lines[] = $indent . '  ' . $name . ': ' . $setting['url'];
                }
            }

            $inner = self::outline(array_values((array) ($element['elements'] ?? [])), $depth + 1);

            if ($inner !== '') {
                $lines[] = $inner;
            }
        }

        return implode("\n", $lines);
    }

    private function watched(int $postId): bool
    {
        if ($postId <= 0 || $this->request->isRestoring() || $this->request->inPostLifecycle()) {
            return false;
        }

        $post = get_post($postId);

        return $post instanceof \WP_Post && $post->post_status !== 'auto-draft'
            && $this->policy->shouldCapture($postId, $post->post_type);
    }

    private function readValue(int $postId, string $field): FieldValue
    {
        return self::toValue($field, array_values(array_map('strval', (array) $this->db->get_col($this->db->prepare(
            "SELECT meta_value FROM {$this->db->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id",
            $postId,
            self::META[$field] ?? ''
        )))));
    }

    /**
     * @param list<string> $rows
     */
    private static function toValue(string $field, array $rows): FieldValue
    {
        if ($rows === [] || $rows[0] === '') {
            return FieldValue::missing();
        }

        if ($field !== 'elementor:data') {
            return FieldValue::of($rows[0]);
        }

        $tree = json_decode($rows[0], true);
        $map = is_array($tree) && array_is_list($tree) ? ElementorTree::flatten($tree) : null;

        return FieldValue::of($map ?? $rows[0]);
    }

    /**
     * The meta value to write; null for a missing value or one this adapter never produces.
     */
    private static function toRaw(string $field, FieldValue $value): ?string
    {
        if (!$value->exists) {
            return null;
        }

        if (is_string($value->value)) {
            return $value->value;
        }

        if ($field === 'elementor:data' && is_array($value->value)) {
            // As Elementor saves it (Document::save_elements).
            $json = wp_json_encode(ElementorTree::build($value->value));

            return is_string($json) ? $json : null;
        }

        return null;
    }

    private static function ksesClean(mixed $value): bool
    {
        if (is_string($value)) {
            return wp_kses_post($value) === $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::ksesClean($item)) {
                    return false;
                }
            }
        }

        return true;
    }
}
