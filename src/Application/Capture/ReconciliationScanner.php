<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Application\Support\SiteFingerprint;
use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\CaptureSink;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;

/**
 * Finds values changed outside WordPress APIs (direct SQL, search-replace, migrations)
 * and records them as changes that can be undone, with the source "external". Only
 * items with history are checked: the latest recorded value is the reference.
 */
final class ReconciliationScanner
{
    public const CURSOR_OPTION = 'selective_undo_reconcile_cursor';
    public const HOOK = 'sundo_reconcile';
    private const BATCH = 500;
    /** Above this share of changed items, the database was most likely restored from a backup. */
    private const RESTORE_SUSPICION_RATIO = 0.2;
    private const RESTORE_SUSPICION_MIN_ITEMS = 50;

    public function __construct(
        private readonly JournalReader $journal,
        private readonly BlobStore $blobs,
        private readonly AdapterRegistry $adapters,
        private readonly CaptureSink $sink,
        private readonly RequestContext $request,
        private readonly OperationManagerInterface $operations,
        private readonly SiteFingerprint $site,
        private readonly TrackingPolicy $policy,
    ) {
    }

    /**
     * Checks the next batch of items with history.
     *
     * @return array{checked: int, drifted: int, done_cycle: bool, restored_backup: bool}
     */
    public function run(float $budgetSeconds): array
    {
        $result = ['checked' => 0, 'drifted' => 0, 'done_cycle' => false, 'restored_backup' => false];

        // While the history may belong to another copy of the database, differences
        // are not edits; the administrator decides first (FR-8.7.2).
        if (!$this->sink->isActive() || $this->site->isMoved()) {
            return $result;
        }

        $started = microtime(true);
        $cursor = (int) get_option(self::CURSOR_OPTION, 0);
        $objects = $this->journal->objectsWithHistoryAfter('post', $cursor, self::BATCH);
        $drift = [];
        $checked = 0;

        foreach ($objects as $objectId) {
            $checked++;
            $cursor = $objectId;

            if (get_post_status($objectId) === 'trash') {
                continue; // moving to the trash changes the status and slug; it is recorded as an event
            }

            foreach ($this->groupByAdapter($this->journal->latestStoredValues(new ObjectRef('post', $objectId))) as $adapterKey => $fields) {
                $adapter = $this->adapters->find($adapterKey);
                $snapshot = $adapter?->read(new ObjectRef('post', $objectId));

                if ($adapter === null || !$snapshot instanceof ObjectSnapshot) {
                    continue; // the item was deleted, or its integration is gone
                }

                $object = new ObjectRef('post', $objectId, $snapshot->objectSubtype());

                if (!$this->policy->shouldCapture($objectId, $object->subtype)) {
                    continue; // recording was turned off for this content type or item
                }

                foreach ($fields as $field => $recorded) {
                    $now = $snapshot->field($field);

                    if (!hash_equals($recorded['hash'], $now->hash())) {
                        $drift[] = [$adapter, $object, $field, $recorded, $now];
                    }
                }
            }

            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }
        }

        $result['checked'] = $checked;
        $result['drifted'] = count(array_unique(array_map(static fn (array $d): int => $d[1]->id, $drift)));
        $result['done_cycle'] = $checked === count($objects) && count($objects) < self::BATCH;
        update_option(self::CURSOR_OPTION, $result['done_cycle'] ? 0 : $cursor, false);

        if ($checked >= self::RESTORE_SUSPICION_MIN_ITEMS && $result['drifted'] > $checked * self::RESTORE_SUSPICION_RATIO) {
            // Hundreds of "changes" at once: report a restored backup instead (FR-8.4.4).
            $this->site->flagRestoredBackup();
            $result['restored_backup'] = true;

            return $result;
        }

        if ($drift !== []) {
            $this->record($drift);
        }

        return $result;
    }

    /** Starts a full pass from the first item (after search-replace, imports, updates). */
    public function restart(): void
    {
        update_option(self::CURSOR_OPTION, 0, false);
    }

    /**
     * @param list<array{0: \SelectiveUndo\Domain\Contracts\AdapterInterface, 1: ObjectRef, 2: string, 3: array{blob_id: int, hash: string}, 4: \SelectiveUndo\Domain\Value\FieldValue}> $drift
     */
    private function record(array $drift): void
    {
        $previous = $this->request->source();
        $this->request->setSource(Source::External);
        $token = $this->operations->begin(__('Changes made outside WordPress (found by the check)', 'selective-undo'), [], Source::External->value);

        try {
            foreach ($drift as [$adapter, $object, $field, $recorded, $now]) {
                $before = $this->blobs->loadVerified($recorded['blob_id'], $recorded['hash']);
                $this->sink->record($adapter, $object, [$field => [$before, $now]], CaptureQuality::Reconciled);
            }

            $this->operations->finish($token);
        } catch (\Throwable $e) {
            $this->operations->fail($token, 'reconcile_failed');

            throw $e;
        } finally {
            $this->request->setSource($previous);
        }
    }

    /**
     * @param array<string, array{adapter: string, blob_id: int, hash: string}> $latest
     *
     * @return array<string, array<string, array{blob_id: int, hash: string}>>
     */
    private function groupByAdapter(array $latest): array
    {
        $out = [];

        foreach ($latest as $field => $row) {
            $out[$row['adapter']][$field] = ['blob_id' => $row['blob_id'], 'hash' => $row['hash']];
        }

        return $out;
    }
}
