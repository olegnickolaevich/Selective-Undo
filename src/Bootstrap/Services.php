<?php

declare(strict_types=1);

namespace SelectiveUndo\Bootstrap;

use SelectiveUndo\Application\Capture\CaptureService;
use SelectiveUndo\Application\Capture\CaptureState;
use SelectiveUndo\Application\Capture\GapRecorder;
use SelectiveUndo\Application\Capture\OperationManager;
use SelectiveUndo\Application\Capture\RequestContext;
use SelectiveUndo\Application\Capture\SourceDetector;
use SelectiveUndo\Infrastructure\Database\DbInfo;
use SelectiveUndo\Infrastructure\Database\SchemaManager;
use SelectiveUndo\Infrastructure\Database\Tables;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;
use SelectiveUndo\Infrastructure\Settings\Settings;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalRepository;
use SelectiveUndo\Infrastructure\WordPress\CapabilityManager;
use SelectiveUndo\Infrastructure\WordPress\PostChangeObserver;
use SelectiveUndo\Infrastructure\WordPress\PostRowReader;
use SelectiveUndo\Infrastructure\WordPress\SystemClock;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;
use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Application\Restore\BuildRestorePlan;
use SelectiveUndo\Application\Restore\CreateRestoreJob;
use SelectiveUndo\Application\Restore\ExecuteRestoreJob;
use SelectiveUndo\Application\Restore\JobView;
use SelectiveUndo\Application\Restore\PlanView;
use SelectiveUndo\Application\Restore\PostRestoreHandler;
use SelectiveUndo\Application\Restore\RestoreObjectExecutor;
use SelectiveUndo\Application\Restore\RestorePreconditions;
use SelectiveUndo\Application\Restore\RestoreReadiness;
use SelectiveUndo\Application\Support\ObjectPresenter;
use SelectiveUndo\Domain\Restore\ChainResolver;
use SelectiveUndo\Domain\Restore\ConflictDetector;
use SelectiveUndo\Domain\Restore\JobOutcome;
use SelectiveUndo\Domain\Restore\PlanItemEvaluator;
use SelectiveUndo\Infrastructure\Database\DbCredentials;
use SelectiveUndo\Infrastructure\Database\RestoreConnection;
use SelectiveUndo\Infrastructure\Database\StorageUnavailable;
use SelectiveUndo\Infrastructure\Storage\JobRepository;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\Storage\Outbox;
use SelectiveUndo\Infrastructure\Storage\PlanRepository;
use SelectiveUndo\Infrastructure\Storage\RestoreJournal;
use SelectiveUndo\Infrastructure\WordPress\CacheInvalidator;
use SelectiveUndo\Infrastructure\WordPress\PostFieldsAdapter;
use SelectiveUndo\Bootstrap\Plugin;
use SelectiveUndo\Application\History\DiffBuilder;
use SelectiveUndo\Application\History\DiffService;
use SelectiveUndo\Application\History\HistoryExport;
use SelectiveUndo\Application\History\HistoryQuery;
use SelectiveUndo\Application\Retention\BlobCollector;
use SelectiveUndo\Application\Retention\PurgeService;
use SelectiveUndo\Application\Retention\RetentionService;
use SelectiveUndo\Application\Health\HealthService;
use SelectiveUndo\Infrastructure\WordPress\PrivacyIntegration;

/**
 * Lazily constructed service graph. Explicit factories keep the wiring readable
 * and statically analysable without a generic container.
 */
final class Services
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function db(): \wpdb
    {
        return $this->db;
    }

    public function tables(): Tables
    {
        return $this->get(Tables::class, fn () => Tables::fromWpdb($this->db));
    }

    public function settings(): Settings
    {
        return $this->get(Settings::class, fn () => new Settings());
    }

    public function schema(): SchemaManager
    {
        return $this->get(SchemaManager::class, fn () => new SchemaManager($this->db, $this->tables()));
    }

    public function dbInfo(): DbInfo
    {
        return $this->get(DbInfo::class, fn () => new DbInfo($this->db));
    }

    public function clock(): SystemClock
    {
        return $this->get(SystemClock::class, fn () => new SystemClock());
    }

    public function capabilities(): CapabilityManager
    {
        return $this->get(CapabilityManager::class, fn () => new CapabilityManager());
    }

    public function trackingPolicy(): TrackingPolicy
    {
        return $this->get(TrackingPolicy::class, fn () => new TrackingPolicy($this->settings()));
    }

    public function requestContext(): RequestContext
    {
        return $this->get(RequestContext::class, fn () => new RequestContext(new SourceDetector()));
    }

    public function eventLog(): EventLog
    {
        return $this->get(EventLog::class, fn () => new EventLog($this->db, $this->tables(), $this->requestContext()->requestUuid()));
    }

    public function gaps(): GapRecorder
    {
        return $this->get(GapRecorder::class, fn () => new GapRecorder($this->db, $this->tables()));
    }

    public function captureState(): CaptureState
    {
        return $this->get(CaptureState::class, fn () => new CaptureState($this->settings(), $this->schema(), $this->gaps()));
    }

    public function blobs(): BlobStore
    {
        return $this->get(BlobStore::class, fn () => new BlobStore($this->db, $this->tables(), $this->settings(), $this->dbInfo()));
    }

    public function journal(): JournalRepository
    {
        return $this->get(JournalRepository::class, fn () => new JournalRepository($this->db, $this->tables()));
    }

    public function capture(): CaptureService
    {
        return $this->get(CaptureService::class, fn () => new CaptureService(
            $this->db,
            $this->journal(),
            $this->blobs(),
            $this->requestContext(),
            $this->captureState(),
            $this->gaps(),
            $this->settings(),
            $this->eventLog(),
            $this->clock(),
        ));
    }

    public function postRowReader(): PostRowReader
    {
        return $this->get(PostRowReader::class, fn () => new PostRowReader($this->db));
    }

    public function observer(): PostChangeObserver
    {
        return $this->get(PostChangeObserver::class, fn () => new PostChangeObserver(
            $this->capture(),
            $this->trackingPolicy(),
            $this->postRowReader(),
            $this->requestContext(),
        ));
    }

    public function operations(): OperationManager
    {
        return $this->get(OperationManager::class, fn () => new OperationManager(
            $this->db,
            $this->tables(),
            $this->requestContext(),
            $this->journal(),
        ));
    }

    public function cacheInvalidator(): CacheInvalidator
    {
        return $this->get(CacheInvalidator::class, fn () => new CacheInvalidator());
    }

    public function postFieldsAdapter(): PostFieldsAdapter
    {
        return $this->get(PostFieldsAdapter::class, fn () => new PostFieldsAdapter(
            $this->db->posts,
            $this->trackingPolicy(),
            $this->clock(),
            $this->postRowReader(),
            $this->cacheInvalidator(),
        ));
    }

    public function adapters(): AdapterRegistry
    {
        return $this->get(AdapterRegistry::class, fn () => new AdapterRegistry(
            fn (AdapterRegistry $registry) => $registry->register($this->postFieldsAdapter())
        ));
    }

    /**
     * Dedicated connection for restore transactions. Each new connection proves that it
     * reaches the same database as WordPress by reading the site instance marker.
     */
    public function restoreConnection(): RestoreConnection
    {
        return $this->get(RestoreConnection::class, fn () => new RestoreConnection(
            fn () => DbCredentials::fromWordPress($this->db),
            $this->db->charset !== '' ? $this->db->charset : 'utf8mb4',
            5,
            function (RestoreConnection $connection): void {
                $expected = (string) get_option(Plugin::INSTANCE_OPTION, '');
                $rows = $connection->select(
                    sprintf('SELECT option_value FROM `%s` WHERE option_name = ?', $this->tables()->options),
                    's',
                    [Plugin::INSTANCE_OPTION]
                );

                if ($expected === '' || $rows === [] || (string) $rows[0]['option_value'] !== $expected) {
                    throw new StorageUnavailable('restore_connection_wrong_database');
                }
            },
        ));
    }

    public function journalReader(): JournalReader
    {
        return $this->get(JournalReader::class, fn () => new JournalReader($this->db, $this->tables()));
    }

    public function plans(): PlanRepository
    {
        return $this->get(PlanRepository::class, fn () => new PlanRepository($this->db, $this->tables()));
    }

    public function jobs(): JobRepository
    {
        return $this->get(JobRepository::class, fn () => new JobRepository($this->db, $this->tables(), $this->restoreConnection()));
    }

    public function restoreJournal(): RestoreJournal
    {
        return $this->get(RestoreJournal::class, fn () => new RestoreJournal($this->tables(), $this->blobs()));
    }

    public function outbox(): Outbox
    {
        return $this->get(Outbox::class, function (): Outbox {
            $outbox = new Outbox($this->db, $this->tables(), $this->eventLog());
            $outbox->on('object_restored', new PostRestoreHandler($this->adapters(), $this->settings()));

            return $outbox;
        });
    }

    public function preconditions(): RestorePreconditions
    {
        return $this->get(RestorePreconditions::class, fn () => new RestorePreconditions());
    }

    public function buildPlan(): BuildRestorePlan
    {
        return $this->get(BuildRestorePlan::class, fn () => new BuildRestorePlan(
            $this->journalReader(),
            $this->plans(),
            $this->blobs(),
            $this->adapters(),
            new ChainResolver(),
            new PlanItemEvaluator(new ConflictDetector()),
            $this->preconditions(),
        ));
    }

    public function objectExecutor(): RestoreObjectExecutor
    {
        return $this->get(RestoreObjectExecutor::class, fn () => new RestoreObjectExecutor(
            $this->restoreConnection(),
            $this->adapters(),
            $this->blobs(),
            $this->jobs(),
            $this->restoreJournal(),
            $this->outbox(),
            new ConflictDetector(),
            $this->preconditions(),
        ));
    }

    public function executeJob(): ExecuteRestoreJob
    {
        return $this->get(ExecuteRestoreJob::class, fn () => new ExecuteRestoreJob(
            $this->jobs(),
            $this->objectExecutor(),
            $this->journal(),
            new JobOutcome(),
            $this->eventLog(),
        ));
    }

    public function readiness(): RestoreReadiness
    {
        return $this->get(RestoreReadiness::class, fn () => new RestoreReadiness(
            $this->db,
            $this->tables(),
            $this->schema(),
            $this->restoreConnection(),
        ));
    }

    public function createJob(): CreateRestoreJob
    {
        return $this->get(CreateRestoreJob::class, fn () => new CreateRestoreJob(
            $this->restoreConnection(),
            $this->tables(),
            $this->plans(),
            $this->jobs(),
            $this->executeJob(),
            $this->readiness(),
            $this->requestContext(),
            $this->restoreJournal(),
        ));
    }

    public function objectPresenter(): ObjectPresenter
    {
        return $this->get(ObjectPresenter::class, fn () => new ObjectPresenter());
    }

    public function planView(): PlanView
    {
        return $this->get(PlanView::class, fn () => new PlanView($this->plans(), $this->jobs(), $this->adapters(), $this->objectPresenter()));
    }

    public function jobView(): JobView
    {
        return $this->get(JobView::class, fn () => new JobView($this->jobs(), $this->plans(), $this->journalReader(), $this->adapters(), $this->objectPresenter()));
    }

    public function history(): HistoryQuery
    {
        return $this->get(HistoryQuery::class, fn () => new HistoryQuery($this->db, $this->tables(), $this->journalReader(), $this->adapters(), $this->objectPresenter()));
    }

    public function diffs(): DiffService
    {
        return $this->get(DiffService::class, fn () => new DiffService($this->journalReader(), $this->plans(), $this->blobs(), $this->adapters(), new DiffBuilder()));
    }

    public function historyExport(): HistoryExport
    {
        return $this->get(HistoryExport::class, fn () => new HistoryExport($this->db, $this->tables()));
    }

    public function blobCollector(): BlobCollector
    {
        return $this->get(BlobCollector::class, fn () => new BlobCollector($this->db, $this->tables()));
    }

    public function retention(): RetentionService
    {
        return $this->get(RetentionService::class, fn () => new RetentionService(
            $this->db,
            $this->tables(),
            $this->settings(),
            $this->plans(),
            $this->journal(),
            $this->captureState(),
            $this->blobCollector(),
            $this->eventLog(),
        ));
    }

    public function purge(): PurgeService
    {
        return $this->get(PurgeService::class, fn () => new PurgeService($this->db, $this->tables(), $this->retention(), $this->blobCollector(), $this->eventLog()));
    }

    public function health(): HealthService
    {
        return $this->get(HealthService::class, fn () => new HealthService(
            $this->db,
            $this->tables(),
            $this->settings(),
            $this->schema(),
            $this->captureState(),
            $this->readiness(),
            $this->retention(),
            $this->dbInfo(),
            $this->cacheInvalidator(),
            $this->adapters(),
        ));
    }

    public function privacy(): PrivacyIntegration
    {
        return $this->get(PrivacyIntegration::class, fn () => new PrivacyIntegration($this->db, $this->tables()));
    }

    /**
     * Drops cached instances whose configuration may have changed (settings update, tests).
     */
    public function reset(string ...$classes): void
    {
        foreach ($classes as $class) {
            unset($this->instances[$class]);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param callable(): T   $factory
     *
     * @return T
     */
    private function get(string $id, callable $factory): object
    {
        /** @var T */
        return $this->instances[$id] ??= $factory();
    }
}
