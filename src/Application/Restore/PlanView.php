<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Application\Support\ObjectPresenter;
use SelectiveUndo\Application\Support\SiteFingerprint;
use SelectiveUndo\Application\Support\Time;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Infrastructure\Storage\JobRepository;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;

/**
 * Read model of a plan for its owner.
 */
final class PlanView
{
    /** Data categories a restore of post fields never touches. */
    public const UNTOUCHED = ['unselected_fields', 'unrecorded_metadata', 'comments', 'users', 'new_items'];

    public function __construct(
        private readonly PlanRepository $plans,
        private readonly JobRepository $jobs,
        private readonly AdapterRegistry $adapters,
        private readonly ObjectPresenter $objects,
        private readonly SiteFingerprint $site,
    ) {
    }

    /**
     * @return array<string, mixed>|null Null when missing or not owned by the user.
     */
    public function forUser(string $uuid, int $userId): ?array
    {
        $plan = $this->plans->findByUuid($uuid);

        if ($plan === null || (int) $plan['actor_user_id'] !== $userId || (int) $plan['blog_id'] !== get_current_blog_id()) {
            return null;
        }

        $status = (string) $plan['status'];

        if ($status === 'ready' && strtotime($plan['expires_at'] . ' UTC') <= time()) {
            $status = 'expired';
        }

        $sources = $this->plans->sources((int) $plan['id']);
        $summary = ['objects' => (int) $plan['object_count'], 'fields' => 0, 'ready' => 0, 'already_restored' => 0,
            'conflicts' => 0, 'blocked' => 0, 'unsupported' => 0, 'missing' => 0, 'forbidden' => 0];
        $map = ['ready' => 'ready', 'already_restored' => 'already_restored', 'conflict' => 'conflicts', 'blocked' => 'blocked',
            'unsupported' => 'unsupported', 'missing_object' => 'missing', 'forbidden' => 'forbidden'];
        $items = [];

        foreach ($this->plans->items((int) $plan['id']) as $row) {
            $summary['fields']++;
            $summary[$map[(string) $row['status']] ?? 'blocked']++;
            $adapter = $this->adapters->find((string) $row['adapter_key']);
            $items[] = [
                'id' => (int) $row['id'],
                'object' => $this->objects->describe(new ObjectRef((string) $row['object_type'], (int) $row['object_id'], (string) $row['object_subtype']), $userId),
                'field' => (string) $row['field_key'],
                'field_label' => $this->adapters->fieldLabel((string) $row['field_key'], (string) $row['adapter_key'], new ObjectRef((string) $row['object_type'], (int) $row['object_id'], (string) $row['object_subtype'])),
                'change_ids' => $sources[(int) $row['id']] ?? [],
                'status' => (string) $row['status'],
                'reason_code' => $row['reason_code'] === null ? null : (string) $row['reason_code'],
                'warnings' => $row['warnings'] === '' ? [] : explode(',', (string) $row['warnings']),
                'merge' => self::merge($row['merge_state'] ?? null),
            ];
        }

        $job = $plan['claimed_job_id'] !== null ? $this->jobs->findById((int) $plan['claimed_job_id']) : null;

        return [
            'id' => (string) $plan['uuid'],
            'status' => $status,
            'created_at' => Time::iso($plan['created_at']),
            'expires_at' => Time::iso($plan['expires_at']),
            'summary' => $summary,
            'untouched' => self::UNTOUCHED,
            'items' => $items,
            'job_id' => $job !== null ? (string) $job['uuid'] : null,
            // The history may come from another site until an administrator decides (FR-8.7.2).
            'site_moved' => $this->site->isMoved(),
            // IDs only: lets the UI build a fresh preview when this one expires.
            'selection' => json_decode((string) $plan['selection_json'], true),
        ];
    }

    /**
     * Merge details for the preview: strategy and the conflicts still to decide.
     *
     * @return array{strategy: string, conflicts: list<array{index: int, label: string}>, resolved: int}|null
     */
    private static function merge(mixed $state): ?array
    {
        $data = is_string($state) && $state !== '' ? json_decode($state, true) : null;

        if (!is_array($data)) {
            return null;
        }

        return [
            'strategy' => (string) ($data['strategy'] ?? ''),
            'conflicts' => array_map(
                static fn (array $c): array => ['index' => (int) ($c['index'] ?? 0), 'label' => (string) ($c['label'] ?? '')],
                array_values(array_filter((array) ($data['conflicts'] ?? []), 'is_array'))
            ),
            'resolved' => (int) ($data['resolved'] ?? 0),
        ];
    }
}
