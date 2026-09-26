<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Cli;

use SelectiveUndo\Bootstrap\Plugin;

/**
 * Maintenance commands. Restoring from the command line is a PRO feature.
 */
final class CliCommand
{
    /**
     * Shows health checks.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function health(array $args, array $assoc): void
    {
        $checks = Plugin::services()->health()->checks();
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $checks, ['id', 'status', 'label', 'message']);
    }

    /**
     * Runs retention, quota enforcement and garbage collection now.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function maintenance(array $args, array $assoc): void
    {
        Plugin::maybeMigrate();
        $done = Plugin::services()->retention()->run(60.0);

        foreach ($done as $key => $count) {
            \WP_CLI::log(sprintf('%s: %d', $key, $count));
        }

        \WP_CLI::success('Maintenance finished.');
    }

    /**
     * Recovers stuck restore jobs and delivers pending post-processing events.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function queue(array $args, array $assoc): void
    {
        Plugin::processQueue();
        \WP_CLI::success('Queue processed.');
    }

    /**
     * Imports recent WordPress revisions saved before recording started.
     *
     * Runs until every item is handled. Normally this happens in the background
     * after activation; use it on large sites or when WP-Cron does not run.
     *
     * @subcommand import-revisions
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function importRevisions(array $args, array $assoc): void
    {
        Plugin::maybeMigrate();
        $importer = Plugin::services()->revisionImporter();
        $items = 0;
        $changes = 0;

        do {
            $result = $importer->run(30.0);
            $items += $result['items'];
            $changes += $result['changes'];
        } while (!$result['done'] && $result['handled'] > 0);

        \WP_CLI::success(sprintf('Imported %d changes of %d items.', $changes, $items));
    }
}
