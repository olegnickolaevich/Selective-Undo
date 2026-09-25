<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Domain\Change\ChangeEvent;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Domain\Restore\ChainResolver;
use SelectiveUndo\Domain\Restore\ChangeLink;
use SelectiveUndo\Domain\Restore\ItemFacts;
use SelectiveUndo\Domain\Restore\PlanItemEvaluator;
use SelectiveUndo\Domain\Restore\PlanItemStatus;
use SelectiveUndo\Domain\Restore\ResolvedChain;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;

/**
 * Builds an immutable restore plan (the preview).
 *
 * The client sends change IDs only; targets always come from the server-side journal.
 */
final class BuildRestorePlan
{
    public const MAX_CHANGES = 500;

    public function __construct(
        private readonly JournalReader $journal,
        private readonly PlanRepository $plans,
        private readonly BlobStore $blobs,
        private readonly AdapterRegistry $adapters,
        private readonly ChainResolver $chains,
        private readonly PlanItemEvaluator $evaluator,
        private readonly RestorePreconditions $preconditions,
    ) {
    }

    /**
     * @param array{type: string, change_ids?: list<int>, changeset_id?: string, fields?: list<string>, object_ids?: list<int>} $selection
     *
     * @return string Plan UUID.
     *
     * @throws DomainError
     */
    public function handle(int $userId, array $selection): string
    {
        $changes = $this->loadSelection($selection);
        $groups = $this->group($changes);
        $this->authorizeView($userId, $groups);
        $this->enforceLimits($groups);

        $plan = $this->plans->create($userId, $selection);
        $snapshots = [];
        $counts = ['item_count' => 0, 'ready_count' => 0, 'object_count' => 0];
        $hashInput = [];
        $objects = [];

        try {
            foreach ($groups as $group) {
                $item = $this->evaluateGroup($userId, $group, $snapshots);
                $this->plans->addItem($plan['id'], $item, $group['change_ids']);
                $counts['item_count']++;
                $counts['ready_count'] += $item['status'] === PlanItemStatus::Ready->value ? 1 : 0;
                $objects[$item['object_type'] . ':' . $item['object_id']] = true;
                $hashInput[] = [
                    $item['object_type'], $item['object_id'], $item['field_key'], $item['status'],
                    bin2hex((string) $item['expected_hash']), bin2hex((string) $item['target_hash']),
                ];
            }

            $counts['object_count'] = count($objects);
            $this->plans->markReady($plan['id'], $counts, hash('sha256', (string) wp_json_encode($hashInput), true));
        } catch (\Throwable $e) {
            $this->plans->markFailed($plan['id']);

            throw $e;
        }

        return $plan['uuid'];
    }

    /**
     * @param array<string, mixed> $selection
     *
     * @return list<array<string, mixed>>
     */
    private function loadSelection(array $selection): array
    {
        $type = $selection['type'] ?? '';

        if ($type === 'changes') {
            $ids = array_values(array_unique(array_map('intval', (array) ($selection['change_ids'] ?? []))));

            if ($ids === [] || count($ids) > self::MAX_CHANGES) {
                throw new DomainError('su_invalid_selection', 400, __('Select between 1 and 500 changes.', 'selective-undo'));
            }

            $rows = $this->journal->changesByIds($ids);

            if (count($rows) !== count($ids)) {
                throw new DomainError('su_not_found', 404, __('Some of the selected changes no longer exist in the history.', 'selective-undo'));
            }

            foreach ($rows as $row) {
                if (!ChangeEvent::from((string) $row['event'])->isFieldChange()) {
                    throw new DomainError('su_invalid_selection', 400, __('Informational events cannot be restored.', 'selective-undo'));
                }
            }

            return array_values($rows);
        }

        if ($type === 'changeset') {
            $changeset = $this->journal->changesetByUuid((string) ($selection['changeset_id'] ?? ''));

            if ($changeset === null) {
                throw new DomainError('su_not_found', 404, __('This operation no longer exists in the history.', 'selective-undo'));
            }

            $fields = array_map('strval', (array) ($selection['fields'] ?? []));
            $objectIds = array_map('intval', (array) ($selection['object_ids'] ?? []));
            $rows = array_values(array_filter(
                $this->journal->changesOfChangeset((int) $changeset['id'], self::MAX_CHANGES + 1),
                static fn (array $r): bool => ChangeEvent::from((string) $r['event'])->isFieldChange()
                    && ($fields === [] || in_array((string) $r['field_key'], $fields, true))
                    && ($objectIds === [] || in_array((int) $r['object_id'], $objectIds, true))
            ));

            if ($rows === []) {
                throw new DomainError('su_invalid_selection', 400, __('The selection contains no restorable changes.', 'selective-undo'));
            }

            if (count($rows) > self::MAX_CHANGES) {
                throw new DomainError('su_selection_too_large', 422, __('Select at most 500 changes at a time.', 'selective-undo'));
            }

            return $rows;
        }

        throw new DomainError('su_invalid_selection', 400, __('Unknown selection type.', 'selective-undo'));
    }

    /**
     * @param list<array<string, mixed>> $changes
     *
     * @return list<array{object: ObjectRef, adapter_key: string, adapter_version: int, field: string, rows: list<array<string, mixed>>, change_ids: list<int>}>
     */
    private function group(array $changes): array
    {
        $groups = [];

        foreach ($changes as $row) {
            $key = $row['object_type'] . ':' . $row['object_id'] . ':' . $row['field_key'];
            $groups[$key] ??= [
                'object' => new ObjectRef((string) $row['object_type'], (int) $row['object_id'], (string) $row['object_subtype']),
                'adapter_key' => (string) $row['adapter_key'],
                'adapter_version' => (int) $row['adapter_version'],
                'field' => (string) $row['field_key'],
                'rows' => [],
                'change_ids' => [],
            ];
            $groups[$key]['rows'][] = $row;
            $groups[$key]['change_ids'][] = (int) $row['id'];
        }

        foreach ($groups as &$group) {
            sort($group['change_ids']);
            // Changes recorded by different adapter versions cannot be folded.
            $group['adapter_version'] = max(array_map(static fn (array $r): int => (int) $r['adapter_version'], $group['rows']));
        }

        return array_values($groups);
    }

    /**
     * Viewing history of every selected object is required; otherwise the whole
     * request fails without revealing which objects exist.
     *
     * @param list<array{object: ObjectRef, adapter_key: string}> $groups
     */
    private function authorizeView(int $userId, array $groups): void
    {
        foreach ($groups as $group) {
            $adapter = $this->adapters->find($group['adapter_key']);
            $allowed = $adapter !== null
                ? $adapter->authorize($userId, $group['object'], 'view')
                : user_can($userId, 'sundo_view_object_history', $group['object']->id, $group['object']->subtype);

            if (!$allowed) {
                throw new DomainError('su_forbidden', 403, __('You are not allowed to view the history of some of the selected items.', 'selective-undo'));
            }
        }
    }

    /**
     * @param list<array{object: ObjectRef}> $groups
     */
    private function enforceLimits(array $groups): void
    {
        $objects = [];

        foreach ($groups as $group) {
            $objects[$group['object']->key()] = true;
        }

        $max = max(1, (int) apply_filters('selective_undo/max_objects_per_plan', 1));

        if (count($objects) > $max) {
            throw new DomainError(
                'su_selection_requires_pro',
                422,
                __('The free version restores one item at a time. Select changes of a single item.', 'selective-undo'),
                ['max_objects' => $max, 'objects' => count($objects)]
            );
        }
    }

    /**
     * @param array{object: ObjectRef, adapter_key: string, adapter_version: int, field: string, rows: list<array<string, mixed>>, change_ids: list<int>} $group
     * @param array<string, ObjectSnapshot|null> $snapshots
     *
     * @return array{object_type: string, object_subtype: string, object_id: int, adapter_key: string,
     *               adapter_version: int, field_key: string, first_change_id: int, last_change_id: int,
     *               chain_length: int, expected_hash: string|null, target_hash: string|null,
     *               target_blob_id: int|null, status: string, reason_code: string|null, warnings: list<string>}
     */
    private function evaluateGroup(int $userId, array $group, array &$snapshots): array
    {
        $object = $group['object'];
        $field = $group['field'];
        $adapter = $this->adapters->find($group['adapter_key']);
        $unsupportedReason = null;

        if ($adapter === null) {
            $unsupportedReason = 'adapter_unavailable';
        } elseif ($adapter->version() !== $group['adapter_version']) {
            $unsupportedReason = 'adapter_version_mismatch';
        }

        $snapshot = null;

        if ($adapter !== null) {
            $snapshot = $snapshots[$object->key()] ??= $adapter->read($object);

            if ($snapshot !== null) {
                // The current post type decides support (a post may have changed type).
                $object = new ObjectRef($object->type, $object->id, $snapshot->objectSubtype());
            }

            if ($unsupportedReason === null && !$adapter->supports($object, $field)) {
                $unsupportedReason = 'post_type_not_tracked';
            }
        }

        $links = array_map(
            static fn (array $r): ChangeLink => new ChangeLink(
                (int) $r['id'],
                (string) $r['before_hash'],
                (string) $r['after_hash'],
                (bool) (int) $r['restorable'] && $r['quality'] !== 'ambiguous' && $r['before_blob_id'] !== null,
                $r['quality'] === 'ambiguous' ? 'capture_ambiguous' : ($r['reason_code'] === null ? null : (string) $r['reason_code'])
            ),
            $group['rows']
        );
        $ids = $group['change_ids'];
        $chain = $this->chains->resolve(
            $links,
            $this->journal->fieldChangeIdsInRange($group['object'], $field, $ids[0], $ids[count($ids) - 1])
        );

        $target = null;
        $targetBlobId = null;
        $targetProblem = null;

        if ($chain instanceof ResolvedChain) {
            $firstRow = $this->rowById($group['rows'], $chain->targetChangeId);
            $targetBlobId = (int) $firstRow['before_blob_id'];

            try {
                $target = $this->blobs->loadVerified($targetBlobId, $chain->targetHash);
            } catch (InvalidPayload $e) {
                $targetProblem = $e->reasonCode;
            }
        }

        $blocking = [];
        $warnings = [];

        if ($adapter !== null && $snapshot !== null && $unsupportedReason === null) {
            $blocking = $this->blockingReasons($adapter, $userId, $object, $field, $snapshot, $target);
            $warnings = $adapter->warnings($snapshot, [$field], $userId);
        }

        $decision = $this->evaluator->evaluate(new ItemFacts(
            supported: $unsupportedReason === null,
            objectExists: $snapshot !== null,
            authorized: $adapter !== null && $snapshot !== null && $adapter->authorize($userId, $object, 'restore'),
            chain: $chain,
            targetPayloadProblem: $targetProblem,
            currentHash: $snapshot?->field($field)->hash(),
            blockingReasons: $blocking,
            warnings: $warnings,
            unsupportedReason: $unsupportedReason,
        ));

        $finalWarnings = $decision->warnings;

        if ($chain instanceof ResolvedChain && $decision->status === PlanItemStatus::Ready) {
            if ($this->journal->hasLaterFieldChanges($group['object'], $field, $chain->expectedChangeId)) {
                // The field changed after the selection and came back to the same value (ABA).
                $finalWarnings[] = 'later_changes_exist';
            }

            $firstRow = $this->rowById($group['rows'], $chain->targetChangeId);

            if ($this->journal->hasGapsSince($group['object'], (string) $firstRow['created_at'])) {
                $finalWarnings[] = 'capture_gap_in_range';
            }
        }

        return [
            'object_type' => $object->type,
            'object_subtype' => $object->subtype,
            'object_id' => $object->id,
            'adapter_key' => $group['adapter_key'],
            'adapter_version' => $group['adapter_version'],
            'field_key' => $field,
            'first_change_id' => $ids[0],
            'last_change_id' => $ids[count($ids) - 1],
            'chain_length' => count($ids),
            'expected_hash' => $chain instanceof ResolvedChain ? $chain->expectedHash : null,
            'target_hash' => $chain instanceof ResolvedChain ? $chain->targetHash : null,
            'target_blob_id' => $chain instanceof ResolvedChain ? $targetBlobId : null,
            'status' => $decision->status->value,
            'reason_code' => $decision->reasonCode,
            'warnings' => array_values(array_unique($finalWarnings)),
        ];
    }

    /**
     * @return list<string>
     */
    private function blockingReasons(
        AdapterInterface $adapter,
        int $userId,
        ObjectRef $object,
        string $field,
        ObjectSnapshot $snapshot,
        ?FieldValue $target,
    ): array {
        $reasons = [];
        $state = $adapter->validateState($snapshot, $field);

        if ($state !== null) {
            $reasons[] = $state;
        }

        if ($target !== null) {
            $targetReason = $adapter->validateTarget($userId, $object, $field, $target);

            if ($targetReason !== null) {
                $reasons[] = $targetReason;
            }
        }

        $external = $this->preconditions->check($object, $field, $userId);

        if ($external !== null) {
            $reasons[] = $external;
        }

        return $reasons;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function rowById(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        throw new \LogicException('Change not in group.');
    }
}
