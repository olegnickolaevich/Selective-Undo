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
use SelectiveUndo\Domain\Contracts\FieldDefinition;
use SelectiveUndo\Domain\Contracts\MergeableAdapter;
use SelectiveUndo\Domain\Contracts\NormalizesValues;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Merge\LinkedOrder;
use SelectiveUndo\Domain\Merge\MergeStrategy;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;

/**
 * Classic navigation menus (Appearance → Menus, the Customizer): the object menu:<term
 * ID>, the field "items" = the published items of the menu as a map keyed by item ID:
 *
 *   id => ['after' => previous item or '', 'title', 'attr_title', 'description', 'meta' => [_menu_item_* => raw]]
 *
 * Undo works item by item and setting by setting: later edits of other items stay.
 * A menu is saved item by item; its state before the first write of a request and at
 * the end of the request are compared, so one save is one change.
 */
final class NavMenuAdapter implements AdapterInterface, CapturingAdapter, DescribesFields, MergeableAdapter, NormalizesValues, PresentsValues
{
    public const KEY = 'core/nav-menus';
    public const VERSION = 1;
    public const FIELD = 'items';
    /** Object subtype of menus; a colon is never part of a post type name. */
    public const SUBTYPE = 'menu:nav';

    private const META = [
        '_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_object_id', '_menu_item_object',
        '_menu_item_target', '_menu_item_classes', '_menu_item_xfn', '_menu_item_url',
    ];

    /** @var array<int, FieldValue> menu term ID => items before the first write of this request */
    private array $pending = [];
    private bool $reading = false;

    public function __construct(
        private readonly \wpdb $db,
        private readonly RequestContext $request,
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

    public function mergeStrategy(string $fieldKey): MergeStrategy
    {
        return MergeStrategy::Keys;
    }

    /**
     * Items in menu order, each after the one before it, settings sorted: as they are
     * read back once written.
     */
    public function normalize(string $fieldKey, FieldValue $value): FieldValue
    {
        if (!$value->exists || !is_array($value->value)) {
            return $value;
        }

        $items = $value->value;
        $out = [];
        $after = '';

        foreach (LinkedOrder::order(array_map(static fn ($i): string => is_array($i) ? (string) ($i['after'] ?? '') : '', $items)) as $id) {
            $item = (array) $items[$id];
            $meta = array_map('strval', (array) ($item['meta'] ?? []));
            ksort($meta);
            // The same shape as toValue().
            $out[$id] = [
                'after' => $after,
                'author' => (string) ($item['author'] ?? '0'),
                'title' => (string) ($item['title'] ?? ''),
                'attr_title' => (string) ($item['attr_title'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'meta' => $meta,
            ];
            $after = $id;
        }

        return FieldValue::of($out);
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            fields: [self::FIELD],
            requiresTransactions: true,
            supportsBackground: true,
            hasLinkedData: true,
            knownSideEffects: ['object_cache', 'term_counts'],
            idempotent: true,
            maxValueBytes: null,
        );
    }

    public function supports(ObjectRef $object, string $fieldKey): bool
    {
        return $object->type === 'menu' && $fieldKey === self::FIELD;
    }

    public function fieldLabel(string $fieldKey): string
    {
        return $fieldKey === self::FIELD ? __('Menu items', 'selective-undo') : $fieldKey;
    }

    public function fields(ObjectRef $object): array
    {
        return [new FieldDefinition(self::FIELD, __('Menu items', 'selective-undo'), 'json', MergeStrategy::Keys)];
    }

    public function registerCapture(CaptureSink $sink): void
    {
        $touch = function (int $itemId, int $ttId = 0) use ($sink): void {
            if ($this->reading || !$sink->isActive() || $this->request->isRestoring()) {
                return;
            }

            foreach ($ttId > 0 ? [$this->menuOfTermTaxonomy($ttId)] : $this->menusOfItem($itemId) as $menuId) {
                if ($menuId > 0 && !isset($this->pending[$menuId])) {
                    $this->pending[$menuId] = $this->readItems($menuId);
                }
            }
        };

        // Every way an item changes, right before the write: its row, its meta, its menu, its deletion.
        add_action('pre_post_update', function (mixed $postId, mixed $data) use ($touch): void {
            if (is_array($data) && ($data['post_type'] ?? '') === 'nav_menu_item') {
                $touch((int) $postId);
            }
        }, PHP_INT_MAX, 2);
        add_action('before_delete_post', function (mixed $postId, mixed $post = null) use ($touch): void {
            if ($post instanceof \WP_Post && $post->post_type === 'nav_menu_item') {
                $touch((int) $postId);
            }
        }, PHP_INT_MAX, 2);
        $meta = function (mixed $check, mixed $postId, mixed $key) use ($touch): mixed {
            if ($check === null && in_array($key, self::META, true)) {
                $touch((int) $postId);
            }

            return $check;
        };
        add_filter('update_post_metadata', $meta, PHP_INT_MAX, 3);
        add_filter('add_post_metadata', $meta, PHP_INT_MAX, 3);
        add_filter('delete_post_metadata', $meta, PHP_INT_MAX, 3);
        add_action('add_term_relationship', function (mixed $objectId, mixed $ttId, mixed $taxonomy) use ($touch): void {
            if ($taxonomy === 'nav_menu') {
                $touch((int) $objectId, (int) $ttId);
            }
        }, PHP_INT_MAX, 3);
        add_action('delete_term_relationships', function (mixed $objectId, mixed $ttIds, mixed $taxonomy) use ($touch): void {
            if ($taxonomy === 'nav_menu') {
                foreach ((array) $ttIds as $ttId) {
                    $touch((int) $objectId, (int) $ttId);
                }
            }
        }, PHP_INT_MAX, 3);

        // Before the post observer and the sealing of the request's change sets.
        add_action('shutdown', fn () => $this->flush($sink), -1);
    }

    /**
     * Records the menus changed so far in this request (called at its end).
     */
    public function flush(CaptureSink $sink): void
    {
        foreach ($this->pending as $menuId => $before) {
            unset($this->pending[$menuId]);
            $after = $this->readItems($menuId);

            if (!$before->equals($after)) {
                $sink->record($this, new ObjectRef('menu', $menuId, self::SUBTYPE), [self::FIELD => [$before, $after]], CaptureQuality::Verified);
            }
        }
    }

    public function read(ObjectRef $object): ?ObjectSnapshot
    {
        if (!$this->menuExists($object->id)) {
            return null;
        }

        return new FieldsSnapshot($object->id, self::SUBTYPE, fn (): FieldValue => $this->readItems($object->id), 'exists');
    }

    public function lockAndRead(RestoreTransaction $tx, ObjectRef $object): ?ObjectSnapshot
    {
        $tt = $tx->select("SELECT term_taxonomy_id FROM `{$this->db->term_taxonomy}` WHERE term_id = ? AND taxonomy = 'nav_menu' FOR UPDATE", 'i', [$object->id]);

        if ($tt === []) {
            return null;
        }

        $posts = $tx->select(
            "SELECT p.ID, p.post_author, p.post_title, p.post_excerpt, p.post_content, p.menu_order FROM `{$this->db->posts}` p
             JOIN `{$this->db->term_relationships}` tr ON tr.object_id = p.ID
             WHERE tr.term_taxonomy_id = ? AND p.post_type = 'nav_menu_item' AND p.post_status = 'publish'
             ORDER BY p.menu_order, p.ID FOR UPDATE",
            'i',
            [(int) $tt[0]['term_taxonomy_id']]
        );
        $meta = $posts === [] ? [] : $tx->select(
            "SELECT post_id, meta_key, meta_value FROM `{$this->db->postmeta}` WHERE post_id IN (" . implode(',', array_map(static fn (array $p): int => (int) $p['ID'], $posts)) . ')
             AND meta_key IN (' . implode(', ', array_fill(0, count(self::META), '?')) . ') ORDER BY meta_id FOR UPDATE',
            str_repeat('s', count(self::META)),
            self::META
        );
        $value = self::toValue($posts, $meta);

        return new FieldsSnapshot($object->id, self::SUBTYPE, static fn (): FieldValue => $value, 'exists');
    }

    public function authorize(int $userId, ObjectRef $object, string $action): bool
    {
        return user_can($userId, $action === 'view' ? Capabilities::VIEW_OBJECT : Capabilities::RESTORE_OBJECT, $object->id, self::SUBTYPE);
    }

    public function validateState(ObjectSnapshot $current, string $fieldKey): ?string
    {
        return $fieldKey === self::FIELD ? null : 'field_not_supported';
    }

    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string
    {
        if ($target->exists && !is_array($target->value)) {
            return 'target_type_mismatch';
        }

        if (!user_can($actorUserId, 'unfiltered_html') && is_array($target->value)) {
            foreach ($target->value as $item) {
                foreach (['title', 'attr_title', 'description'] as $text) {
                    $value = is_array($item) ? (string) ($item[$text] ?? '') : '';

                    if (wp_kses_post($value) !== $value) {
                        return 'requires_unfiltered_html';
                    }
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
        $target = $values[self::FIELD] ?? null;
        $items = $target instanceof FieldValue && is_array($target->value) ? $target->value : ($target?->exists === false ? [] : null);

        if ($items === null) {
            throw new \LogicException('Menu items are not restorable.');
        }

        $menuId = $current->objectId();
        $tt = $tx->select("SELECT term_taxonomy_id FROM `{$this->db->term_taxonomy}` WHERE term_id = ? AND taxonomy = 'nav_menu'", 'i', [$menuId]);

        if ($tt === []) {
            throw new StorageError('menu_missing');
        }

        $ttId = (int) $tt[0]['term_taxonomy_id'];
        $now = $current->field(self::FIELD);
        $existing = $now->exists && is_array($now->value) ? $now->value : [];

        // Items the target does not have: removed as WordPress removes menu items.
        foreach (array_diff(array_map('strval', array_keys($existing)), array_map('strval', array_keys($items))) as $id) {
            $tx->write("DELETE FROM `{$this->db->postmeta}` WHERE post_id = ?", 'i', [(int) $id]);
            $tx->write("DELETE FROM `{$this->db->term_relationships}` WHERE object_id = ?", 'i', [(int) $id]);
            $tx->write("DELETE FROM `{$this->db->posts}` WHERE ID = ? AND post_type = 'nav_menu_item'", 'i', [(int) $id]);
        }

        $order = LinkedOrder::order(array_map(static fn ($item): string => is_array($item) ? (string) ($item['after'] ?? '') : '', $items));
        $date = gmdate('Y-m-d H:i:s');

        foreach ($order as $position => $id) {
            $item = (array) $items[$id];
            $row = $tx->select("SELECT ID, post_type FROM `{$this->db->posts}` WHERE ID = ? FOR UPDATE", 'i', [(int) $id]);

            if ($row !== [] && $row[0]['post_type'] !== 'nav_menu_item') {
                throw new StorageError('menu_item_id_taken');
            }

            $text = [(string) ($item['title'] ?? ''), (string) ($item['attr_title'] ?? ''), (string) ($item['description'] ?? ''), $position + 1];

            if ($row !== []) {
                $tx->write(
                    "UPDATE `{$this->db->posts}` SET post_title = ?, post_excerpt = ?, post_content = ?, menu_order = ?, post_status = 'publish' WHERE ID = ?",
                    'sssii',
                    [...$text, (int) $id]
                );
            } elseif ($tx->write(
                "INSERT INTO `{$this->db->posts}` (ID, post_author, post_date, post_date_gmt, post_title, post_excerpt, post_content, menu_order, post_status, post_name, post_type, post_modified, post_modified_gmt, to_ping, pinged, post_content_filtered, guid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'publish', ?, 'nav_menu_item', ?, ?, '', '', '', '')",
                'iisssssisss',
                [(int) $id, (int) ($item['author'] ?? 0), $date, $date, ...$text, (string) $id, $date, $date]
            ) !== 1) {
                throw new StorageError('menu_item_insert_failed');
            }

            $tx->write(
                "DELETE FROM `{$this->db->postmeta}` WHERE post_id = ? AND meta_key IN (" . implode(', ', array_fill(0, count(self::META), '?')) . ')',
                'i' . str_repeat('s', count(self::META)),
                [(int) $id, ...self::META]
            );

            foreach ((array) ($item['meta'] ?? []) as $key => $value) {
                if (in_array($key, self::META, true)) {
                    $tx->write("INSERT INTO `{$this->db->postmeta}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)", 'iss', [(int) $id, (string) $key, (string) $value]);
                }
            }

            if ($tx->select("SELECT object_id FROM `{$this->db->term_relationships}` WHERE object_id = ? AND term_taxonomy_id = ?", 'ii', [(int) $id, $ttId]) === []) {
                $tx->write("INSERT INTO `{$this->db->term_relationships}` (object_id, term_taxonomy_id, term_order) VALUES (?, ?, 0)", 'ii', [(int) $id, $ttId]);
            }
        }
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        $items = $this->readItems($object->id);

        foreach (is_array($items->value) ? array_keys($items->value) : [] as $id) {
            clean_post_cache((int) $id);
        }

        $term = get_term($object->id, 'nav_menu');

        if ($term instanceof \WP_Term) {
            wp_update_term_count_now([(int) $term->term_taxonomy_id], 'nav_menu');
        }

        clean_term_cache([$object->id], 'nav_menu');
        // Lets caches of other plugins refresh, as a save of the menu screen would.
        $this->request->whileRestoring(static function () use ($object): void {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action, fired as wp_update_nav_menu_object() does.
            do_action('wp_update_nav_menu', $object->id);
        });
    }

    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string
    {
        if (!$value->exists || !is_array($value->value)) {
            return '';
        }

        $items = $value->value;
        $lines = [];

        foreach (LinkedOrder::order(array_map(static fn ($i): string => is_array($i) ? (string) ($i['after'] ?? '') : '', $items)) as $id) {
            $item = (array) $items[$id];
            $meta = (array) ($item['meta'] ?? []);
            $depth = 0;
            $parent = (string) ($meta['_menu_item_menu_item_parent'] ?? '0');

            while ($parent !== '0' && $parent !== '' && isset($items[$parent]) && $depth < 10) {
                $depth++;
                $parent = (string) (((array) ((array) $items[$parent])['meta'])['_menu_item_menu_item_parent'] ?? '0');
            }

            $type = (string) ($meta['_menu_item_type'] ?? '');
            $target = match ($type) {
                'custom' => (string) ($meta['_menu_item_url'] ?? ''),
                'post_type' => get_the_title((int) ($meta['_menu_item_object_id'] ?? 0)) . ' (#' . (string) ($meta['_menu_item_object_id'] ?? '') . ')',
                'taxonomy' => (static function () use ($meta): string {
                    $term = get_term((int) ($meta['_menu_item_object_id'] ?? 0), (string) ($meta['_menu_item_object'] ?? ''));

                    return ($term instanceof \WP_Term ? $term->name : '') . ' (#' . (string) ($meta['_menu_item_object_id'] ?? '') . ')';
                })(),
                default => $type,
            };
            $title = (string) ($item['title'] ?? '');
            $lines[] = str_repeat('    ', $depth) . ($title !== '' ? $title : '…') . ' → ' . $target;
        }

        return implode("\n", $lines);
    }

    private function readItems(int $menuId): FieldValue
    {
        $this->reading = true;

        try {
            $ttId = (int) $this->db->get_var($this->db->prepare("SELECT term_taxonomy_id FROM {$this->db->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu'", $menuId));
            $posts = $ttId === 0 ? [] : (array) $this->db->get_results($this->db->prepare(
                "SELECT p.ID, p.post_author, p.post_title, p.post_excerpt, p.post_content, p.menu_order FROM {$this->db->posts} p
                 JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
                 WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item' AND p.post_status = 'publish'
                 ORDER BY p.menu_order, p.ID",
                $ttId
            ), ARRAY_A);
            $meta = $posts === [] ? [] : (array) $this->db->get_results($this->db->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$this->db->postmeta} WHERE post_id IN (" . implode(',', array_map(static fn (array $p): int => (int) $p['ID'], $posts)) . ')
                 AND meta_key IN (' . implode(', ', array_fill(0, count(self::META), '%s')) . ') ORDER BY meta_id',
                ...self::META
            ), ARRAY_A);
        } finally {
            $this->reading = false;
        }

        return self::toValue($posts, $meta);
    }

    /**
     * @param array<array-key, mixed> $posts
     * @param array<array-key, mixed> $meta
     */
    private static function toValue(array $posts, array $meta): FieldValue
    {
        $byPost = [];

        foreach ($meta as $m) {
            if (is_array($m) && in_array($m['meta_key'] ?? '', self::META, true)) {
                $byPost[(int) $m['post_id']][(string) $m['meta_key']] = (string) $m['meta_value'];
            }
        }

        $items = [];
        $after = '';

        foreach ($posts as $p) {
            if (!is_array($p)) {
                continue;
            }

            $id = (string) $p['ID'];
            $values = $byPost[(int) $id] ?? [];
            ksort($values);
            $items[$id] = [
                'after' => $after,
                'author' => (string) $p['post_author'],
                'title' => (string) $p['post_title'],
                'attr_title' => (string) $p['post_excerpt'],
                'description' => (string) $p['post_content'],
                'meta' => $values,
            ];
            $after = $id;
        }

        return FieldValue::of($items);
    }

    private function menuExists(int $menuId): bool
    {
        return $this->db->get_var($this->db->prepare("SELECT term_id FROM {$this->db->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu'", $menuId)) !== null;
    }

    /**
     * @return list<int>
     */
    private function menusOfItem(int $itemId): array
    {
        $this->reading = true;

        try {
            return array_values(array_map('intval', (array) $this->db->get_col($this->db->prepare(
                "SELECT tt.term_id FROM {$this->db->term_relationships} tr JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id = %d AND tt.taxonomy = 'nav_menu'",
                $itemId
            ))));
        } finally {
            $this->reading = false;
        }
    }

    private function menuOfTermTaxonomy(int $ttId): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT term_id FROM {$this->db->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = 'nav_menu'", $ttId));
    }
}
