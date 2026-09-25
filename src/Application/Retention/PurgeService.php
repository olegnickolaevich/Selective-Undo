<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Retention;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Application\Support\Time;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;

/**
 * User-initiated history deletion (privacy, space). Always two steps: a preview
 * returns counts and a signed token bound to the criteria; deletion requires the
 * token and typing DELETE. Data of active plans and running jobs is skipped.
 */
final class PurgeService
{
    public const CONFIRMATION = 'DELETE';
    private const TOKEN_TTL = 600;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
        private readonly RetentionService $retention,
        private readonly BlobCollector $blobs,
        private readonly EventLog $log,
    ) {
    }

    /**
     * @param array{scope: string, object_id?: int, before?: string} $criteria
     *
     * @return array{token: string, changesets: int, changes: int, protected_changesets: int, criteria: array<string, mixed>}
     */
    public function preview(array $criteria, int $userId): array
    {
        $criteria = $this->normalize($criteria);
        [$where, $params] = $this->where($criteria);
        $sql = "SELECT COUNT(*) FROM {$this->t->changesets} cs WHERE cs.status IN ('open', 'sealed') {$where}";
        $total = (int) $this->db->get_var($params === [] ? $sql : $this->db->prepare($sql, ...$params));
        $changes = $this->countChanges($criteria);
        $deletable = $this->retention->countPrunable(null, $where, $params);
        $expires = time() + self::TOKEN_TTL;
        $payload = base64_encode((string) wp_json_encode(['c' => $criteria, 'u' => $userId, 'e' => $expires]));

        return [
            'token' => $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth')),
            'changesets' => $total,
            'changes' => $changes,
            'protected_changesets' => max(0, $total - $deletable),
            'criteria' => $criteria,
            'expires_at' => Time::iso(gmdate('Y-m-d H:i:s', $expires)),
        ];
    }

    /**
     * @return array{deleted_changesets: int, deleted_changes: int, remaining: bool}
     */
    public function execute(string $token, string $confirmation, int $userId, float $budgetSeconds = 20.0): array
    {
        if ($confirmation !== self::CONFIRMATION) {
            throw new DomainError('su_confirmation_required', 400, __('Type DELETE to confirm.', 'selective-undo'));
        }

        $criteria = $this->verify($token, $userId);
        $started = microtime(true);
        $deletedChangesets = 0;
        $deletedChanges = 0;

        if ($criteria['scope'] === 'object') {
            $deletedChanges = $this->purgeObject((int) $criteria['object_id']);
            $remaining = false;
        } else {
            [$where, $params] = $this->where($criteria);

            do {
                $ids = $this->retention->prunableBefore(null, 200, $where, $params);

                if ($ids !== []) {
                    $deletedChanges += (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->t->changes . ' WHERE changeset_id IN (' . implode(',', $ids) . ')');
                }

                $deletedChangesets += $this->retention->deleteChangesets($ids);
            } while ($ids !== [] && microtime(true) - $started < $budgetSeconds);

            $remaining = $this->retention->prunableBefore(null, 1, $where, $params) !== [];
        }

        $this->blobs->collect(max(1.0, $budgetSeconds - (microtime(true) - $started)), 0);
        $this->retention->refreshStats();
        $this->log->log('info', 'history_purged', [
            'scope' => $criteria['scope'],
            'changesets' => $deletedChangesets,
            'changes' => $deletedChanges,
            'actor_user_id' => $userId,
        ]);

        return ['deleted_changesets' => $deletedChangesets, 'deleted_changes' => $deletedChanges, 'remaining' => $remaining];
    }

    /**
     * Deletes all field changes and events of one object except those protected by
     * active plans or running jobs; empty changesets are removed.
     */
    private function purgeObject(int $objectId): int
    {
        $deleted = (int) $this->db->query($this->db->prepare(
            "DELETE c FROM {$this->t->changes} c
             WHERE c.object_type = 'post' AND c.object_id = %d
               AND NOT EXISTS (
                 SELECT 1 FROM {$this->t->planItemSources} s
                 JOIN {$this->t->planItems} pi ON pi.id = s.plan_item_id
                 JOIN {$this->t->plans} p ON p.id = pi.plan_id
                 LEFT JOIN {$this->t->jobs} j ON j.plan_id = p.id
                 WHERE s.change_id = c.id AND (p.status IN ('preparing', 'ready') OR j.status IN ('queued', 'running', 'cancel_requested')))",
            $objectId
        ));
        $this->db->query(
            "DELETE cs FROM {$this->t->changesets} cs
             WHERE cs.status = 'sealed' AND NOT EXISTS (SELECT 1 FROM {$this->t->changes} c WHERE c.changeset_id = cs.id)"
        );
        $this->db->query($this->db->prepare(
            "DELETE FROM {$this->t->gaps} WHERE object_type = 'post' AND object_id = %d",
            $objectId
        ));

        return $deleted;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array{scope: string, object_id?: int, before?: string}
     */
    private function normalize(array $criteria): array
    {
        $scope = (string) ($criteria['scope'] ?? '');

        return match ($scope) {
            'object' => (int) ($criteria['object_id'] ?? 0) > 0
                ? ['scope' => 'object', 'object_id' => (int) $criteria['object_id']]
                : throw new DomainError('su_invalid_request', 400, __('Choose an item.', 'selective-undo')),
            'before' => Time::mysqlFromIso((string) ($criteria['before'] ?? '')) !== null
                ? ['scope' => 'before', 'before' => (string) Time::mysqlFromIso((string) $criteria['before'])]
                : throw new DomainError('su_invalid_request', 400, __('Choose a valid date.', 'selective-undo')),
            'all' => ['scope' => 'all'],
            default => throw new DomainError('su_invalid_request', 400, __('Unknown deletion scope.', 'selective-undo')),
        };
    }

    /**
     * @param array{scope: string, object_id?: int, before?: string} $criteria
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function where(array $criteria): array
    {
        return match ($criteria['scope']) {
            'object' => [" AND EXISTS (SELECT 1 FROM {$this->t->changes} cx WHERE cx.changeset_id = cs.id AND cx.object_type = 'post' AND cx.object_id = %d)", [(int) $criteria['object_id']]],
            'before' => [' AND cs.created_at < %s', [(string) $criteria['before']]],
            default => ['', []],
        };
    }

    /**
     * @param array{scope: string, object_id?: int, before?: string} $criteria
     */
    private function countChanges(array $criteria): int
    {
        return match ($criteria['scope']) {
            'object' => (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t->changes} WHERE object_type = 'post' AND object_id = %d", (int) $criteria['object_id'])),
            'before' => (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t->changes} c JOIN {$this->t->changesets} cs ON cs.id = c.changeset_id WHERE cs.created_at < %s", (string) $criteria['before'])),
            default => (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t->changes}"),
        };
    }

    /**
     * @return array{scope: string, object_id?: int, before?: string}
     */
    private function verify(string $token, int $userId): array
    {
        [$payload, $mac] = array_pad(explode('.', $token, 2), 2, '');

        if ($mac === '' || !hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $mac)) {
            throw new DomainError('su_invalid_token', 400, __('The deletion preview is invalid. Preview again.', 'selective-undo'));
        }

        $data = json_decode((string) base64_decode($payload, true), true);

        if (!is_array($data) || (int) ($data['u'] ?? 0) !== $userId || (int) ($data['e'] ?? 0) < time()) {
            throw new DomainError('su_invalid_token', 400, __('The deletion preview has expired. Preview again.', 'selective-undo'));
        }

        /** @var array{scope: string, object_id?: int, before?: string} */
        return $data['c'];
    }
}
