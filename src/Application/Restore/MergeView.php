<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper), never printed as HTML.

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\MergeableAdapter;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;

/**
 * Texts of the merge conflicts of one plan item. They are not stored with the plan:
 * the merge is repeated from the journal (it is deterministic) and checked against
 * the hashes the plan recorded.
 */
final class MergeView
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly JournalReader $journal,
        private readonly BlobStore $blobs,
        private readonly AdapterRegistry $adapters,
        private readonly FieldMerger $merger = new FieldMerger(),
    ) {
    }

    /**
     * @return list<array{index: int, label: string, current_html: string, restored_html: string, current_text: string, restored_text: string}>
     *
     * @throws DomainError
     */
    public function conflicts(string $planUuid, int $itemId, int $userId): array
    {
        $plan = $this->plans->findByUuid($planUuid);
        $item = $plan !== null && (int) $plan['actor_user_id'] === $userId ? $this->plans->item((int) $plan['id'], $itemId) : null;

        if ($plan === null || $item === null || $item['reason_code'] !== 'merge_conflict') {
            throw new DomainError('su_not_found', 404, __('This preview has no conflicts to show.', 'selective-undo'));
        }

        $adapter = $this->adapters->find((string) $item['adapter_key']);
        $object = new ObjectRef((string) $item['object_type'], (int) $item['object_id'], (string) $item['object_subtype']);
        $field = (string) $item['field_key'];
        $snapshot = $adapter?->read($object);

        if (!$adapter instanceof MergeableAdapter || $snapshot === null || !$adapter->authorize($userId, $object, 'restore')) {
            throw new DomainError('su_not_found', 404, __('This preview has no conflicts to show.', 'selective-undo'));
        }

        $current = $snapshot->field($field);

        if (!hash_equals((string) $item['expected_hash'], $current->hash())) {
            throw new DomainError('su_plan_stale', 409, __('The content changed after this preview. Check the restore again.', 'selective-undo'));
        }

        // Undoing changes first..last: base is the value after the last, the target the value before the first.
        $rows = $this->journal->changesByIds([(int) $item['first_change_id'], (int) $item['last_change_id']]);
        $first = $rows[(int) $item['first_change_id']] ?? null;
        $last = $rows[(int) $item['last_change_id']] ?? null;

        try {
            if ($first === null || $last === null) {
                throw new InvalidPayload('payload_missing');
            }

            $restored = $this->blobs->loadVerified((int) $first['before_blob_id'], (string) $first['before_hash']);
            $base = $this->blobs->loadVerified((int) $last['after_blob_id'], (string) $last['after_hash']);
        } catch (InvalidPayload) {
            throw new DomainError('su_not_found', 404, __('This preview has no conflicts to show.', 'selective-undo'));
        }

        $selection = json_decode((string) $plan['selection_json'], true);
        $key = $object->type . ':' . $object->id . ':' . $field;
        $resolutions = [];

        foreach ((array) ($selection['resolutions'][$key] ?? []) as $index => $side) {
            $resolutions[(int) $index] = $side === 'restored' ? 'restored' : 'current';
        }

        $outcome = $this->merger->undo($adapter->mergeStrategy($field), $base, $current, $restored, $resolutions);

        return array_map(static fn (array $c): array => [
            'index' => $c['index'],
            'label' => $c['label'],
            'current_html' => $c['current'],
            'restored_html' => $c['restored'],
            'current_text' => trim(wp_strip_all_tags($c['current'])),
            'restored_text' => trim(wp_strip_all_tags($c['restored'])),
        ], $outcome->conflicts);
    }
}
