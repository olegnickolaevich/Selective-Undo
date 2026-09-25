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
