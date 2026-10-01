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
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Merge\MergeStrategy;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalReader;

/**
 * Terms of a post in each taxonomy shown in the admin ("tax:<taxonomy>"). The value
 * is the sorted list of term IDs. Undo works per term: terms added or removed later
 * stay as they are.
 */
final class TermsAdapter implements AdapterInterface, MergeableAdapter, CapturingAdapter, DescribesFields, PresentsValues
{
    public const KEY = 'core/terms';
    public const VERSION = 1;

    /** @var array<string, FieldValue> "postId|taxonomy" => terms before a removal */
    private array $pending = [];

    public function __construct(
        private readonly \wpdb $db,
        private readonly TrackingPolicy $policy,
        private readonly RequestContext $request,
        private readonly CacheInvalidator $caches,
        private readonly JournalReader $journal,
        private readonly BlobStore $blobs,
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
        return MergeStrategy::Set;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            fields: [],
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
        return $object->type === 'post' && str_starts_with($fieldKey, 'tax:')
            && $this->policy->isTrackedPostType($object->subtype)
            && $this->tracked($object->subtype, substr($fieldKey, 4));
    }

    public function fieldLabel(string $fieldKey): string
    {
        $taxonomy = get_taxonomy(substr($fieldKey, 4));

        return $taxonomy instanceof \WP_Taxonomy ? (string) $taxonomy->labels->name : substr($fieldKey, 4);
    }

    public function fields(ObjectRef $object): array
    {
        $fields = [];

        foreach (get_object_taxonomies($object->subtype, 'objects') as $name => $taxonomy) {
            if ($this->tracked($object->subtype, (string) $name)) {
                $fields[] = new FieldDefinition('tax:' . $name, (string) $taxonomy->labels->name, 'int_list', MergeStrategy::Set);
            }
        }

        return $fields;
    }

    public function registerCapture(CaptureSink $sink): void
    {
        add_action('set_object_terms', function (mixed $objectId, mixed $terms, mixed $ttIds, mixed $taxonomy, mixed $append, mixed $oldTtIds) use ($sink): void {
            $objectId = (int) $objectId;
            $taxonomy = (string) $taxonomy;
            unset($this->pending[$objectId . '|' . $taxonomy]);

            if ($sink->isActive() && $this->watched($objectId, $taxonomy)) {
                $this->report($sink, $objectId, $taxonomy, FieldValue::of($this->termIdsOf(array_values(array_map('intval', (array) $oldTtIds)))));
            }
        }, PHP_INT_MAX, 6);

        // wp_remove_object_terms() outside wp_set_object_terms() (which reports itself above).
        add_action('delete_term_relationships', function (mixed $objectId, mixed $ttIds, mixed $taxonomy) use ($sink): void {
            if ($sink->isActive() && $this->watched((int) $objectId, (string) $taxonomy) && !$this->insideSetObjectTerms()) {
                $this->pending[(int) $objectId . '|' . $taxonomy] = $this->readValue((int) $objectId, (string) $taxonomy);
            }
        }, PHP_INT_MAX, 3);

        add_action('deleted_term_relationships', function (mixed $objectId, mixed $ttIds, mixed $taxonomy) use ($sink): void {
            $slot = (int) $objectId . '|' . $taxonomy;

            if (isset($this->pending[$slot])) {
                $before = $this->pending[$slot];
                unset($this->pending[$slot]);
                $this->report($sink, (int) $objectId, (string) $taxonomy, $before);
            }
        }, PHP_INT_MAX, 3);
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
            fn (string $field): FieldValue => $this->readValue($object->id, substr($field, 4)),
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
            "SELECT tt.taxonomy, tt.term_id FROM `{$this->db->term_relationships}` tr
             JOIN `{$this->db->term_taxonomy}` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = ? FOR UPDATE",
            'i',
            [$object->id]
        );
        $byTaxonomy = [];

        foreach ($rows as $row) {
            $byTaxonomy[(string) $row['taxonomy']][] = (int) $row['term_id'];
        }

        return new FieldsSnapshot(
            $object->id,
            (string) $post[0]['post_type'],
            static fn (string $field): FieldValue => FieldValue::of(self::normalize($byTaxonomy[substr($field, 4)] ?? [])),
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
            !str_starts_with($fieldKey, 'tax:') || !taxonomy_exists(substr($fieldKey, 4)) => 'field_not_supported',
            !$this->policy->isTrackedPostType($current->objectSubtype()) => 'post_type_not_tracked',
            $current->status === 'trash' => 'object_trashed',
            default => null,
        };
    }

    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string
    {
        $taxonomy = get_taxonomy(substr($fieldKey, 4));
        $ids = self::ids($target);

        if (!$taxonomy instanceof \WP_Taxonomy || $ids === null) {
            return 'target_type_mismatch';
        }

        if (!user_can($actorUserId, $taxonomy->cap->assign_terms)) {
            return 'terms_not_allowed';
        }

        if ($ids !== [] && count($this->ttIds($taxonomy->name, $ids)) !== count($ids)) {
            return 'term_missing';
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
            $taxonomy = substr($field, 4);
            $ids = self::ids($value);

            if (!str_starts_with($field, 'tax:') || $ids === null) {
                throw new \LogicException('Target type does not match the field.');
            }

            $target = [];

            if ($ids !== []) {
                $rows = $tx->select(
                    "SELECT term_taxonomy_id FROM `{$this->db->term_taxonomy}` WHERE taxonomy = ? AND term_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')',
                    's' . str_repeat('i', count($ids)),
                    [$taxonomy, ...$ids]
                );
                $target = array_map(static fn (array $r): int => (int) $r['term_taxonomy_id'], $rows);

                if (count($target) !== count($ids)) {
                    throw new StorageError('term_missing');
                }
            }

            $existing = array_map(static fn (array $r): int => (int) $r['term_taxonomy_id'], $tx->select(
                "SELECT tr.term_taxonomy_id FROM `{$this->db->term_relationships}` tr
                 JOIN `{$this->db->term_taxonomy}` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id = ? AND tt.taxonomy = ?",
                'is',
                [$current->objectId(), $taxonomy]
            ));
            $remove = array_values(array_diff($existing, $target));

            if ($remove !== []) {
                $tx->write(
                    "DELETE FROM `{$this->db->term_relationships}` WHERE object_id = ? AND term_taxonomy_id IN (" . implode(', ', array_fill(0, count($remove), '?')) . ')',
                    str_repeat('i', count($remove) + 1),
                    [$current->objectId(), ...$remove]
                );
            }

            foreach (array_diff($target, $existing) as $ttId) {
                $tx->write(
                    "INSERT INTO `{$this->db->term_relationships}` (object_id, term_taxonomy_id, term_order) VALUES (?, ?, 0)",
                    'ii',
                    [$current->objectId(), $ttId]
                );
            }
        }
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        $type = $object->subtype !== '' ? $object->subtype : (string) get_post_type($object->id);
        clean_object_term_cache($object->id, $type);

        foreach ($fieldKeys as $field) {
            $taxonomy = substr($field, 4);
            // Counts of the terms the restore removed and of those it added. The removed
            // ones are the "before" of the restore, kept in the journal (retries included).
            $ids = self::ids($this->readValue($object->id, $taxonomy)) ?? [];
            $last = $this->journal->fieldChangesNewestFirst($object, $field, 1)[0] ?? null;

            if ($last !== null && $last['before_blob_id'] !== null && $last['before_hash'] !== null) {
                try {
                    $ids = [...$ids, ...(self::ids($this->blobs->loadVerified($last['before_blob_id'], $last['before_hash'])) ?? [])];
                } catch (\Throwable) {
                    // Only the counts of removed terms stay stale until the next edit.
                }
            }

            $ttIds = $ids === [] ? [] : $this->ttIds($taxonomy, array_values(array_unique($ids)));

            if ($ttIds !== []) {
                wp_update_term_count_now($ttIds, $taxonomy);
            }
        }

        $this->caches->purgePost($object->id);
    }

    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string
    {
        $names = [];

        foreach (self::ids($value) ?? [] as $id) {
            $term = get_term($id);
            $names[] = $term instanceof \WP_Term ? $term->name : '#' . $id;
        }

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return implode("\n", $names);
    }

    private function report(CaptureSink $sink, int $objectId, string $taxonomy, FieldValue $before): void
    {
        $after = $this->readValue($objectId, $taxonomy);

        if (!$before->equals($after)) {
            $sink->record($this, new ObjectRef('post', $objectId, (string) get_post_type($objectId)), [
                'tax:' . $taxonomy => [$before, $after],
            ], CaptureQuality::Verified);
        }
    }

    private function tracked(string $postType, string $taxonomy): bool
    {
        $object = get_taxonomy($taxonomy);

        return $object instanceof \WP_Taxonomy && $object->show_ui && is_object_in_taxonomy($postType, $taxonomy);
    }

    private function watched(int $objectId, string $taxonomy): bool
    {
        if ($objectId <= 0 || $this->request->isRestoring() || $this->request->inPostLifecycle()) {
            return false;
        }

        $post = get_post($objectId);

        return $post instanceof \WP_Post && $post->post_status !== 'auto-draft'
            && $this->policy->shouldCapture($objectId, $post->post_type)
            && $this->tracked($post->post_type, $taxonomy);
    }

    private function insideSetObjectTerms(): bool
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Detects the caller only, nothing is printed.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            if ($frame['function'] === 'wp_set_object_terms') {
                return true;
            }
        }

        return false;
    }

    private function readValue(int $objectId, string $taxonomy): FieldValue
    {
        return FieldValue::of(self::normalize(array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT tt.term_id FROM {$this->db->term_relationships} tr
             JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy = %s",
            $objectId,
            $taxonomy
        )))));
    }

    /**
     * @param list<int> $ttIds
     *
     * @return list<int>
     */
    private function termIdsOf(array $ttIds): array
    {
        if ($ttIds === []) {
            return [];
        }

        return self::normalize(array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT term_id FROM {$this->db->term_taxonomy} WHERE term_taxonomy_id IN (" . implode(', ', array_fill(0, count($ttIds), '%d')) . ')',
            ...$ttIds
        ))));
    }

    /**
     * @param list<int> $termIds
     *
     * @return list<int>
     */
    private function ttIds(string $taxonomy, array $termIds): array
    {
        return array_values(array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT term_taxonomy_id FROM {$this->db->term_taxonomy} WHERE taxonomy = %s AND term_id IN (" . implode(', ', array_fill(0, count($termIds), '%d')) . ')',
            $taxonomy,
            ...$termIds
        ))));
    }

    /**
     * @param array<int> $ids
     *
     * @return list<int>
     */
    private static function normalize(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>|null
     */
    private static function ids(FieldValue $value): ?array
    {
        if (!$value->exists) {
            return [];
        }

        if (!is_array($value->value) || !array_is_list($value->value) || array_filter($value->value, 'is_int') !== $value->value) {
            return null;
        }

        return $value->value;
    }
}
