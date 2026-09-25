<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

/**
 * Table names of the current site. Never built from request data.
 */
final readonly class Tables
{
    public string $operations;
    public string $changesets;
    public string $changes;
    public string $blobs;
    public string $gaps;
    public string $plans;
    public string $planItems;
    public string $planItemSources;
    public string $jobs;
    public string $jobItems;
    public string $outbox;
    public string $eventLog;
    public string $posts;
    public string $options;

    public function __construct(public string $prefix, string $postsTable, string $optionsTable)
    {
        $p = $prefix . 'sundo_';
        $this->operations = $p . 'operations';
        $this->changesets = $p . 'changesets';
        $this->changes = $p . 'changes';
        $this->blobs = $p . 'blobs';
        $this->gaps = $p . 'capture_gaps';
        $this->plans = $p . 'restore_plans';
        $this->planItems = $p . 'restore_plan_items';
        $this->planItemSources = $p . 'plan_item_sources';
        $this->jobs = $p . 'restore_jobs';
        $this->jobItems = $p . 'restore_job_items';
        $this->outbox = $p . 'outbox';
        $this->eventLog = $p . 'event_log';
        $this->posts = $postsTable;
        $this->options = $optionsTable;
    }

    public static function fromWpdb(\wpdb $db): self
    {
        return new self($db->prefix, $db->posts, $db->options);
    }

    /**
     * @return list<string> Plugin-owned tables.
     */
    public function own(): array
    {
        return [
            $this->operations, $this->changesets, $this->changes, $this->blobs, $this->gaps,
            $this->plans, $this->planItems, $this->planItemSources, $this->jobs, $this->jobItems,
            $this->outbox, $this->eventLog,
        ];
    }
}
