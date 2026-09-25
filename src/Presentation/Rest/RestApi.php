<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Rest;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Application\Capture\CaptureState;
use SelectiveUndo\Bootstrap\Services;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Infrastructure\WordPress\Capabilities;

/**
 * REST API /selective-undo/v1. Route permission callbacks are a coarse gate;
 * per-object authorization always happens inside the services.
 */
final class RestApi
{
    public const NS = 'selective-undo/v1';
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private ErrorMapper $errors;

    public function __construct(private readonly Services $s)
    {
        $this->errors = new ErrorMapper($s->eventLog(), $s->requestContext()->requestUuid());
    }

    public function register(): void
    {
        $view = $this->can(Capabilities::VIEW_HISTORY);
        $restore = $this->can(Capabilities::RESTORE_CHANGES);
        $manage = $this->can(Capabilities::MANAGE_SETTINGS);
        $retention = $this->can(Capabilities::MANAGE_RETENTION);
        $export = $this->can(Capabilities::EXPORT_HISTORY);
        $cursor = ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_-]*$'];
        $limit = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25];
        $offset = ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'default' => 0];

        $this->route('/overview', 'GET', $view, [$this, 'overview']);

        $this->route('/changesets', 'GET', $view, [$this, 'listChangesets'], [
            'cursor' => $cursor,
            'limit' => $limit,
            'actor' => ['type' => 'string', 'pattern' => '^(me|[0-9]{1,19})$'],
            'kind' => ['type' => 'string', 'enum' => ['edit', 'autosave', 'operation', 'restore']],
            'source' => ['type' => 'string', 'pattern' => '^[a-z_]{1,32}$'],
            'object_id' => ['type' => 'integer', 'minimum' => 1],
            'subtype' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,20}$'],
            'from' => ['type' => 'string', 'format' => 'date-time'],
            'to' => ['type' => 'string', 'format' => 'date-time'],
            'search' => ['type' => 'string', 'maxLength' => 100],
        ]);
        $this->route('/changesets/(?P<id>' . self::UUID . ')', 'GET', $view, [$this, 'getChangeset']);
        $this->route('/objects/post/(?P<id>\d+)/changes', 'GET', $view, [$this, 'objectTimeline'], ['cursor' => $cursor, 'limit' => $limit]);
        $this->route('/changes/(?P<id>\d+)/diff', 'GET', $view, [$this, 'changeDiff'], ['offset' => $offset]);
        $this->route('/gaps', 'GET', $view, [$this, 'gaps'], ['limit' => $limit]);
        $this->route('/adapters', 'GET', $view, [$this, 'adapters']);

        $this->route('/restore-plans', 'POST', $restore, [$this, 'createPlan'], [
            'selection' => [
                'required' => true,
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['changes', 'changeset'], 'required' => true],
                    'change_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'uniqueItems' => true, 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'changeset_id' => ['type' => 'string', 'format' => 'uuid'],
                    'fields' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_:./-]{1,191}$']],
                    'object_ids' => ['type' => 'array', 'maxItems' => 500, 'items' => ['type' => 'integer', 'minimum' => 1]],
                ],
                'additionalProperties' => false,
            ],
        ]);
        $this->route('/restore-plans/(?P<id>' . self::UUID . ')', 'GET', $restore, [$this, 'getPlan']);
        $this->route('/restore-plans/(?P<id>' . self::UUID . ')/items/(?P<item>\d+)/diff', 'GET', $restore, [$this, 'planItemDiff'], [
            'view' => ['type' => 'string', 'enum' => ['current_target', 'conflict'], 'default' => 'current_target'],
            'offset' => $offset,
        ]);

        $this->route('/restore-jobs', 'POST', $restore, [$this, 'createJob'], [
            'plan_id' => ['required' => true, 'type' => 'string', 'format' => 'uuid'],
            'idempotency_key' => ['required' => true, 'type' => 'string', 'format' => 'uuid'],
        ]);
        $jobsGate = fn (): bool => current_user_can(Capabilities::RESTORE_CHANGES) || current_user_can(Capabilities::MANAGE_SETTINGS);
        $this->route('/restore-jobs', 'GET', $jobsGate, [$this, 'listJobs'], ['cursor' => $cursor, 'limit' => $limit]);
        $this->route('/restore-jobs/(?P<id>' . self::UUID . ')', 'GET', $jobsGate, [$this, 'getJob']);
        $this->route('/restore-jobs/(?P<id>' . self::UUID . ')/items', 'GET', $jobsGate, [$this, 'jobItems'], [
            'status' => ['type' => 'string', 'enum' => ['pending', 'restored', 'already_restored', 'conflict', 'skipped', 'failed', 'cancelled']],
            'after' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            'limit' => $limit,
        ]);
        $this->route('/restore-jobs/(?P<id>' . self::UUID . ')/cancel', 'POST', $jobsGate, [$this, 'cancelJob']);
        $this->route('/restore-jobs/(?P<id>' . self::UUID . ')/run', 'POST', $restore, [$this, 'runJob']);

        $this->route('/settings', 'GET', $manage, [$this, 'getSettings']);
        $this->route('/settings', 'PATCH', $manage, [$this, 'updateSettings'], [
            'settings' => ['type' => 'object'],
            'roles' => ['type' => 'object'],
        ]);
        $this->route('/health', 'GET', $manage, [$this, 'health']);
        $this->route('/diagnostics', 'GET', $manage, [$this, 'diagnostics']);
        $this->route('/history/export', 'GET', $export, [$this, 'exportHistory']);
        $this->route('/history/purge-preview', 'POST', $retention, [$this, 'purgePreview'], [
            'scope' => ['required' => true, 'type' => 'string', 'enum' => ['object', 'before', 'all']],
            'object_id' => ['type' => 'integer', 'minimum' => 1],
            'before' => ['type' => 'string', 'format' => 'date-time'],
        ]);
        $this->route('/history/purge', 'POST', $retention, [$this, 'purge'], [
            'token' => ['required' => true, 'type' => 'string', 'maxLength' => 2048],
            'confirmation' => ['required' => true, 'type' => 'string', 'maxLength' => 32],
        ]);
    }

    public function overview(): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->health()->overview() + [
            'user' => [
                'id' => get_current_user_id(),
                'can' => [
                    'view' => current_user_can(Capabilities::VIEW_HISTORY),
                    'restore' => current_user_can(Capabilities::RESTORE_CHANGES),
                    'manage_settings' => current_user_can(Capabilities::MANAGE_SETTINGS),
                    'manage_retention' => current_user_can(Capabilities::MANAGE_RETENTION),
                    'export' => current_user_can(Capabilities::EXPORT_HISTORY),
                ],
            ],
            'limits' => ['max_objects_per_plan' => max(1, (int) apply_filters('selective_undo/max_objects_per_plan', 1))],
        ]);
    }

    public function listChangesets(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): array {
            $filters = [];

            foreach (['kind', 'source', 'subtype', 'from', 'to', 'search'] as $key) {
                if ($r->get_param($key) !== null && $r->get_param($key) !== '') {
                    $filters[$key] = (string) $r->get_param($key);
                }
            }

            if ($r->get_param('actor') !== null) {
                $filters['actor'] = $r->get_param('actor') === 'me' ? get_current_user_id() : (int) $r->get_param('actor');
            }

            if ($r->get_param('object_id') !== null) {
                $filters['object_id'] = (int) $r->get_param('object_id');
            }

            return $this->s->history()->list(get_current_user_id(), $filters, $this->nullable($r->get_param('cursor')), (int) $r->get_param('limit'));
        });
    }

    public function getChangeset(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->history()->changeset((string) $r['id'], get_current_user_id()));
    }

    public function objectTimeline(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->history()->objectTimeline(
            new ObjectRef('post', (int) $r['id']),
            get_current_user_id(),
            $this->nullable($r->get_param('cursor')),
            (int) $r->get_param('limit')
        ));
    }

    public function changeDiff(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->diffs()->change((int) $r['id'], get_current_user_id(), (int) $r->get_param('offset')));
    }

    public function gaps(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): array {
            $db = $this->s->db();
            $userId = get_current_user_id();
            $rows = (array) $db->get_results($db->prepare(
                "SELECT id, reason, scope, object_type, object_id, occurrences, started_at, last_seen_at, ended_at
                 FROM {$this->s->tables()->gaps} ORDER BY last_seen_at DESC, id DESC LIMIT %d",
                (int) $r->get_param('limit') * 3
            ), ARRAY_A);
            $items = [];

            foreach ($rows as $row) {
                $object = null;

                if ($row['scope'] === 'object') {
                    if (!user_can($userId, Capabilities::VIEW_OBJECT, (int) $row['object_id'])) {
                        continue;
                    }

                    $object = $this->s->objectPresenter()->describe(new ObjectRef((string) $row['object_type'], (int) $row['object_id']), $userId);
                }

                $items[] = [
                    'id' => (int) $row['id'],
                    'reason' => (string) $row['reason'],
                    'scope' => (string) $row['scope'],
                    'object' => $object,
                    'occurrences' => (int) $row['occurrences'],
                    'started_at' => \SelectiveUndo\Application\Support\Time::iso($row['started_at']),
                    'last_seen_at' => \SelectiveUndo\Application\Support\Time::iso($row['last_seen_at']),
                    'ended_at' => \SelectiveUndo\Application\Support\Time::iso($row['ended_at']),
                ];

                if (count($items) >= (int) $r->get_param('limit')) {
                    break;
                }
            }

            return ['items' => $items];
        });
    }

    public function adapters(): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => ['items' => array_values(array_map(static fn ($a): array => [
            'key' => $a->key(),
            'version' => $a->version(),
            'fields' => array_map(static fn (string $f): array => ['key' => $f, 'label' => $a->fieldLabel($f)], $a->capabilities()->fields),
            'side_effects' => $a->capabilities()->knownSideEffects,
        ], $this->s->adapters()->all()))]);
    }

    public function createPlan(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): \WP_REST_Response {
            $selection = (array) $r->get_param('selection');
            $uuid = $this->s->buildPlan()->handle(get_current_user_id(), $selection);

            return new \WP_REST_Response($this->s->planView()->forUser($uuid, get_current_user_id()), 201);
        });
    }

    public function getPlan(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->planView()->forUser((string) $r['id'], get_current_user_id())
            ?? throw new DomainError('su_not_found', 404, __('This preview does not exist.', 'selective-undo')));
    }

    public function planItemDiff(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->diffs()->planItem(
            (string) $r['id'],
            (int) $r['item'],
            get_current_user_id(),
            (string) $r->get_param('view'),
            (int) $r->get_param('offset')
        ));
    }

    public function createJob(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): \WP_REST_Response {
            $result = $this->s->createJob()->handle(get_current_user_id(), (string) $r->get_param('plan_id'), (string) $r->get_param('idempotency_key'));
            $job = $this->s->jobView()->forUser($result['job_uuid'], get_current_user_id());

            return new \WP_REST_Response($job, $result['created'] ? 201 : 200);
        });
    }

    public function listJobs(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): array {
            $db = $this->s->db();
            $userId = get_current_user_id();
            $all = current_user_can(Capabilities::MANAGE_SETTINGS);
            $before = PHP_INT_MAX;
            $cursor = $this->nullable($r->get_param('cursor'));

            if ($cursor !== null) {
                $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
                $before = $decoded !== false && preg_match('/^j1:(\d+)$/', $decoded, $m) ? (int) $m[1] : throw new DomainError('su_invalid_cursor', 400);
            }

            $limit = (int) $r->get_param('limit');
            $rows = (array) $db->get_results($db->prepare(
                "SELECT * FROM {$this->s->tables()->jobs} WHERE id < %d AND blog_id = %d" . ($all ? '' : ' AND actor_user_id = %d') . ' ORDER BY id DESC LIMIT %d',
                ...array_values(array_filter([$before, get_current_blog_id(), $all ? null : $userId, $limit + 1], static fn ($v): bool => $v !== null))
            ), ARRAY_A);
            $next = null;

            if (count($rows) > $limit) {
                array_pop($rows);
                $next = rtrim(strtr(base64_encode('j1:' . $rows[count($rows) - 1]['id']), '+/', '-_'), '=');
            }

            return ['items' => array_map(fn (array $row): array => $this->s->jobView()->present($row, $userId), $rows), 'next_cursor' => $next];
        });
    }

    public function getJob(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->jobView()->forUser((string) $r['id'], get_current_user_id())
            ?? throw new DomainError('su_not_found', 404, __('This restore does not exist.', 'selective-undo')));
    }

    public function jobItems(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): array {
            $job = $this->visibleJob((string) $r['id']);
            $limit = (int) $r->get_param('limit');
            $items = $this->s->jobView()->items($job, get_current_user_id(), $this->nullable($r->get_param('status')), (int) $r->get_param('after'), $limit);

            return ['items' => $items, 'next_after' => count($items) === $limit ? $items[count($items) - 1]['id'] : null];
        });
    }

    public function cancelJob(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): array {
            $job = $this->visibleJob((string) $r['id']);
            $this->s->jobs()->requestCancel((int) $job['id']);

            if ($job['status'] === 'queued') {
                $this->s->executeJob()->run((int) $job['id'], 5.0);
            }

            return $this->s->jobView()->forUser((string) $r['id'], get_current_user_id()) ?? [];
        });
    }

    /**
     * Processes a batch in this request: fallback when background tasks do not run.
     */
    public function runJob(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): array {
            $job = $this->visibleJob((string) $r['id']);

            if ((int) $job['actor_user_id'] !== get_current_user_id()) {
                throw new DomainError('su_forbidden', 403, __('Only the author of a restore can continue it.', 'selective-undo'));
            }

            $this->s->executeJob()->run((int) $job['id'], 10.0);

            return $this->s->jobView()->forUser((string) $r['id'], get_current_user_id()) ?? [];
        });
    }

    public function getSettings(): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->settingsPayload());
    }

    public function updateSettings(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(function () use ($r): array {
            $patch = $r->get_param('settings');
            $roles = $r->get_param('roles');
            $before = $this->s->settings()->captureEnabled();

            if (is_array($patch) && $patch !== []) {
                $this->s->settings()->update($patch);
            }

            if (is_array($roles) && $roles !== []) {
                $this->s->capabilities()->updateRoles($roles);
            }

            $after = $this->s->settings()->captureEnabled();

            if ($before && !$after) {
                $this->s->gaps()->openGlobal('capture_disabled');
            } elseif (!$before && $after) {
                $this->s->gaps()->closeGlobal('capture_disabled');
            }

            $this->s->eventLog()->log('info', 'settings_updated', ['actor_user_id' => get_current_user_id()]);

            return $this->settingsPayload();
        });
    }

    public function health(): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => ['checks' => $this->s->health()->checks(), 'overview' => $this->s->health()->overview()]);
    }

    public function diagnostics(): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->health()->export());
    }

    public function exportHistory(): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => [
            'filename' => 'selective-undo-history-' . gmdate('Ymd-His') . '.csv',
            'content' => $this->s->historyExport()->csv(),
        ]);
    }

    public function purgePreview(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->purge()->preview(array_filter([
            'scope' => (string) $r->get_param('scope'),
            'object_id' => $r->get_param('object_id'),
            'before' => $r->get_param('before'),
        ], static fn ($v): bool => $v !== null), get_current_user_id()));
    }

    public function purge(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->errors->run(fn () => $this->s->purge()->execute(
            (string) $r->get_param('token'),
            (string) $r->get_param('confirmation'),
            get_current_user_id()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        $settings = $this->s->settings();
        $tracked = $settings->trackedPostTypes();
        $excluded = $settings->excludedPostTypes();
        $types = [];

        foreach (get_post_types(['show_ui' => true], 'objects') as $name => $type) {
            if (in_array($name, $excluded, true)) {
                continue;
            }

            $types[] = [
                'name' => (string) $name,
                'label' => (string) $type->labels->name,
                'tracked' => in_array($name, $tracked, true),
                'supports_editor' => post_type_supports((string) $name, 'editor'),
            ];
        }

        return [
            'settings' => $settings->all(),
            'limits' => [
                'retention_max_days' => $settings->maxRetentionDays(),
                'quota_min_mb' => \SelectiveUndo\Infrastructure\Settings\Settings::MIN_QUOTA_MB,
                'quota_max_mb' => \SelectiveUndo\Infrastructure\Settings\Settings::MAX_QUOTA_MB,
                'effective_max_value_bytes' => $settings->maxValueBytes($this->s->dbInfo()->maxAllowedPacket()),
            ],
            'post_types' => $types,
            'roles' => $this->s->capabilities()->roleMatrix(),
            'capabilities' => [
                ['key' => Capabilities::VIEW_HISTORY, 'label' => __('View history', 'selective-undo')],
                ['key' => Capabilities::RESTORE_CHANGES, 'label' => __('Restore changes', 'selective-undo')],
                ['key' => Capabilities::MANAGE_SETTINGS, 'label' => __('Manage settings', 'selective-undo')],
                ['key' => Capabilities::MANAGE_RETENTION, 'label' => __('Delete history', 'selective-undo')],
                ['key' => Capabilities::EXPORT_HISTORY, 'label' => __('Export history', 'selective-undo')],
            ],
            'capture_state' => $this->s->captureState()->describe(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleJob(string $uuid): array
    {
        $job = $this->s->jobs()->findByUuid($uuid);

        if ($job === null || !$this->s->jobView()->canSee($job, get_current_user_id())) {
            throw new DomainError('su_not_found', 404, __('This restore does not exist.', 'selective-undo'));
        }

        return $job;
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function can(string $cap): \Closure
    {
        return static fn (): bool => current_user_can($cap);
    }

    /**
     * @param array<string, array<string, mixed>> $args
     */
    private function route(string $path, string $method, callable $permission, callable $callback, array $args = []): void
    {
        register_rest_route(self::NS, $path, [
            'methods' => $method,
            'callback' => $callback,
            'permission_callback' => $permission,
            'args' => $args,
        ]);
    }
}
