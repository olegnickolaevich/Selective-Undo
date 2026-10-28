<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;
use SelectiveUndo\Infrastructure\Storage\Outbox;

/**
 * Tells listeners that a change set was recorded: right away through an action, and
 * later, delivered at least once, to the handlers registered for the outbox topic.
 * Only IDs and metadata are published, never field values.
 */
final class ChangesetEvents
{
    public const TOPIC = 'changeset_recorded';
    private const MAX_OBJECTS = 100;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly Outbox $outbox,
        private readonly EventLog $log,
    ) {
    }

    public function published(int $changesetId): void
    {
        // Most sites have no listener: then no summary is read on the save path.
        if (!has_action('selective_undo/changeset_recorded') && !$this->outbox->hasHandlers(self::TOPIC)) {
            return;
        }

        try {
            $summary = $this->summary($changesetId);

            if ($summary === null) {
                return;
            }

            /**
             * A change set was recorded (or completed by a later request of the same save).
             * IDs and metadata only, never values.
             *
             * @param array{uuid: string, kind: string, source: string, actor: int|null, objects: list<string>, fields: list<string>, change_count: int} $summary
             */
            do_action('selective_undo/changeset_recorded', $summary);

            // Nothing is written when nobody listens asynchronously.
            if ($this->outbox->hasHandlers(self::TOPIC)) {
                $this->outbox->enqueueNow(self::TOPIC, null, null, $summary);
            }
        } catch (\Throwable $e) {
            // Recording never fails because of a listener (I-6).
            $this->log->exception('changeset_event_failed', $e);
        }
    }

    /**
     * @return array{uuid: string, kind: string, source: string, actor: int|null, objects: list<string>, fields: list<string>, change_count: int}|null
     */
    private function summary(int $changesetId): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT uuid, kind, source, actor_user_id, change_count, summary_json FROM {$this->t->changesets} WHERE id = %d",
            $changesetId
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        $objects = (array) $this->db->get_col($this->db->prepare(
            "SELECT DISTINCT CONCAT(object_type, ':', object_id) FROM {$this->t->changes} WHERE changeset_id = %d LIMIT %d",
            $changesetId,
            self::MAX_OBJECTS
        ));
        $details = json_decode((string) $row['summary_json'], true);

        return [
            'uuid' => (string) $row['uuid'],
            'kind' => (string) $row['kind'],
            'source' => (string) $row['source'],
            'actor' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            'objects' => array_values(array_map('strval', $objects)),
            'fields' => is_array($details) && is_array($details['fields'] ?? null) ? array_values(array_map('strval', $details['fields'])) : [],
            'change_count' => (int) $row['change_count'],
        ];
    }
}
