<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Infrastructure\Database\StorageError;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Storage\JournalRepository;

final class OperationManager implements OperationManagerInterface
{
    public const ABANDON_AFTER_SECONDS = DAY_IN_SECONDS;
    private const MAX_METADATA_BYTES = 4096;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly RequestContext $request,
        private readonly JournalRepository $journal,
    ) {
    }

    public function begin(string $label, array $metadata = [], string $source = 'integration'): OperationToken
    {
        $active = $this->request->operation();

        if ($active !== null) {
            // Nested begin() joins the outer operation; only the outermost finish() closes it.
            $active['depth']++;
            $this->request->setOperation($active);

            return new OperationToken($active['uuid'], $active['depth']);
        }

        foreach ($metadata as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new DomainError('su_invalid_operation', 400, 'Operation metadata must be a flat map of scalars.');
            }
        }

        $json = wp_json_encode($metadata);

        if ($json === false || strlen($json) > self::MAX_METADATA_BYTES) {
            throw new DomainError('su_invalid_operation', 400, 'Operation metadata is too large.');
        }

        $uuid = wp_generate_uuid4();
        $now = time();
        $ok = $this->db->insert($this->t->operations, [
            'uuid' => $uuid,
            'label' => mb_substr(sanitize_text_field($label), 0, 255),
            'source' => substr(sanitize_key($source), 0, 64),
            'actor_user_id' => get_current_user_id() ?: null,
            'status' => 'active',
            'metadata_json' => $json,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'updated_at' => gmdate('Y-m-d H:i:s', $now),
            'abandon_after' => gmdate('Y-m-d H:i:s', $now + self::ABANDON_AFTER_SECONDS),
        ]);

        if ($ok === false) {
            throw new StorageError('operation_insert_failed');
        }

        $this->request->setOperation(['id' => (int) $this->db->insert_id, 'uuid' => $uuid, 'depth' => 1]);

        return new OperationToken($uuid, 1);
    }

    public function resume(string $operationId): OperationToken
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT id, uuid FROM {$this->t->operations} WHERE uuid = %s AND status = 'active'",
            $operationId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainError('su_operation_not_active', 409, 'The operation does not exist or is no longer active.');
        }

        $this->db->query($this->db->prepare(
            "UPDATE {$this->t->operations} SET updated_at = UTC_TIMESTAMP(), abandon_after = %s WHERE id = %d",
            gmdate('Y-m-d H:i:s', time() + self::ABANDON_AFTER_SECONDS),
            (int) $row['id']
        ));
        $this->request->setOperation(['id' => (int) $row['id'], 'uuid' => (string) $row['uuid'], 'depth' => 1]);

        return new OperationToken((string) $row['uuid'], 1);
    }

    public function finish(OperationToken $token): void
    {
        $this->close($token, 'finished', null);
    }

    public function fail(OperationToken $token, string $reasonCode): void
    {
        $this->close($token, 'failed', substr(sanitize_key($reasonCode), 0, 64));
    }

    private function close(OperationToken $token, string $status, ?string $reason): void
    {
        $active = $this->request->operation();

        if ($active !== null && $active['uuid'] === $token->id && $active['depth'] > 1) {
            $active['depth']--;
            $this->request->setOperation($active);

            return;
        }

        if ($active !== null && $active['uuid'] === $token->id) {
            $key = 'operation:' . $active['id'];
            $changesetId = $this->request->changesetId($key);

            if ($changesetId !== null) {
                $this->journal->seal($changesetId);
                $this->request->forgetChangeset($key);
            }

            $this->request->setOperation(null);
        }

        $this->db->query($this->db->prepare(
            "UPDATE {$this->t->operations}
             SET status = %s, reason_code = " . ($reason === null ? 'NULL' : '%s') . ", finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE uuid = %s AND status = 'active'",
            ...array_values(array_filter([$status, $reason, $token->id], static fn ($v): bool => $v !== null))
        ));
    }
}
