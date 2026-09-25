<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterCapabilities;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Contracts\WallClock;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;

/**
 * Standard wp_posts fields. The field list is hard-coded and never extended from
 * request data. Writes go through a direct, whitelisted UPDATE (see ADR-001):
 * wp_update_post() would run arbitrary save hooks inside the restore.
 */
final class PostFieldsAdapter implements AdapterInterface
{
    public const KEY = 'core/post-fields';
    public const VERSION = 1;

    public const FIELDS = ['post_title', 'post_content', 'post_excerpt', 'menu_order'];

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
            'menu_order' => __('Order', 'selective-undo'),
            default => $fieldKey,
        };
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            fields: self::FIELDS,
            requiresTransactions: true,
            supportsBackground: true,
            hasLinkedData: false,
            knownSideEffects: ['post_modified', 'object_cache', 'page_cache', 'wp_revision'],
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
            sprintf(
                'SELECT ID, post_type, post_status, post_title, post_content, post_excerpt, menu_order
                 FROM `%s` WHERE ID = ? FOR UPDATE',
                $this->postsTable
            ),
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
        if ($fieldKey === 'menu_order') {
            return is_int($target->value) ? null : 'target_type_mismatch';
        }

        if (!isset(self::KSES_CONTEXT[$fieldKey]) || !is_string($target->value)) {
            return 'target_type_mismatch';
        }

        // A direct SQL write bypasses kses. A user without unfiltered_html must not
        // bring back markup WordPress would not let them save. kses normalisation
        // may cause false positives; that is a safe refusal.
        if (!user_can($actorUserId, 'unfiltered_html')) {
            $filtered = wp_kses($target->value, self::KSES_CONTEXT[$fieldKey]);

            if ($filtered !== $target->value) {
                return 'requires_unfiltered_html';
            }
        }

        return null;
    }

    public function warnings(ObjectSnapshot $current, array $fieldKeys, int $viewerUserId): array
    {
        assert($current instanceof PostRow);
        $warnings = [];

        // Another user has the post open in an editor: saving their stale form
        // would overwrite the restored values.
        $lock = (string) get_post_meta($current->id, '_edit_lock', true);
        $parts = array_map('intval', explode(':', $lock) + [0, 0]);
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
            if ((string) get_post_field('post_content_filtered', $current->id, 'raw') !== '') {
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

            $sets[] = sprintf('`%s` = ?', $field);

            if ($field === 'menu_order') {
                $types .= 'i';
                $params[] = (int) $value->value;
            } else {
                $types .= 's';
                $params[] = (string) $value->value;
            }
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
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        $this->caches->purgePost($object->id);
    }
}
