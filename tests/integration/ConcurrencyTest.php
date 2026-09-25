<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

/**
 * Real parallel processes (spec 36.3), not sequential simulations.
 */
final class ConcurrencyTest extends IntegrationTestCase
{
    public function testParallelStartsOfOnePlanCreateOneJob(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);

        $results = $this->race([
            ['create_job', $planId, wp_generate_uuid4()],
            ['create_job', $planId, wp_generate_uuid4()],
            ['create_job', $planId, wp_generate_uuid4()],
        ]);

        $ok = array_values(array_filter($results, static fn (array $r): bool => $r['ok'] === true));
        $this->assertCount(1, $ok, json_encode($results));
        $this->assertSame(1, (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->s->tables()->jobs}"));
        $this->assertCount(1, $this->changes($id, 'restore'));
        wp_cache_flush();
        $this->assertSame('Контакты', get_post($id)->post_title);
    }

    public function testParallelRequestsWithSameKeyReturnSameJob(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Two']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($id)]);
        $key = wp_generate_uuid4();

        $results = $this->race([['create_job', $planId, $key], ['create_job', $planId, $key]]);
        $uuids = array_unique(array_map(static fn (array $r): string => (string) ($r['result']['job_uuid'] ?? $r['code']), $results));
        $this->assertCount(1, $uuids, json_encode($results));
        $this->assertCount(1, $this->changes($id, 'restore'));
    }

    public function testTwoWorkersOnOneJobApplyOnce(): void
    {
        $jobId = $this->queuedJob($id);
        $results = $this->race([['run_job', (string) $jobId], ['run_job', (string) $jobId]]);
        $finished = array_filter($results, static fn (array $r): bool => ($r['result']['finished'] ?? false) === true);
        $this->assertCount(1, $finished, json_encode($results));
        $this->assertCount(1, $this->changes($id, 'restore'));
        $this->assertSame('completed', $this->s->jobs()->findById($jobId)['status']);
    }

    public function testConcurrentSaveHoldingTheRowIsRespected(): void
    {
        $jobId = $this->queuedJob($id);
        $other = $this->rawConnection();
        $other->query('START TRANSACTION');
        $other->query("SELECT ID FROM {$this->db->posts} WHERE ID = {$id} FOR UPDATE");
        $other->query("UPDATE {$this->db->posts} SET post_title = 'Saved concurrently' WHERE ID = {$id}");

        $process = $this->spawn(['run_job', (string) $jobId], $barrier);
        touch($barrier);
        usleep(1_500_000); // the worker is now waiting on the row lock
        $other->query('COMMIT');
        $result = $this->collect($process);
        @unlink($barrier);

        $this->assertTrue($result['ok'], json_encode($result));
        $job = $this->s->jobs()->findById($jobId);
        $this->assertSame('completed_with_conflicts', $job['status']);
        $this->assertSame('1', (string) $job['conflict_items']);
        wp_cache_flush();
        $this->assertSame('Saved concurrently', get_post($id)->post_title, 'the newer save is never overwritten');
    }

    private function queuedJob(?int &$postId): int
    {
        $postId = $this->createPost();
        wp_update_post(['ID' => $postId, 'post_title' => 'Two']);
        $this->endRequest();
        $planId = $this->plan(['type' => 'changes', 'change_ids' => $this->updateIds($postId)]);
        $this->db->query("UPDATE {$this->s->tables()->plans} SET object_count = 999 WHERE uuid = '{$planId}'");
        $job = $this->start($planId);
        $this->assertSame('queued', $job['status']);

        return (int) $this->s->jobs()->findByUuid($job['id'])['id'];
    }

    /**
     * @param list<list<string>> $commands
     *
     * @return list<array<string, mixed>>
     */
    private function race(array $commands): array
    {
        $barrier = sys_get_temp_dir() . '/sundo-barrier-' . wp_generate_uuid4();
        $processes = array_map(fn (array $cmd) => $this->spawn($cmd, $barrier), $commands);
        usleep(1_200_000); // let every child load WordPress and wait on the barrier
        touch($barrier);
        $results = array_map(fn ($p) => $this->collect($p), $processes);
        @unlink($barrier);

        return $results;
    }

    /**
     * @param list<string> $command
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawn(array $command, ?string &$barrier = null)
    {
        $barrier ??= sys_get_temp_dir() . '/sundo-barrier-' . wp_generate_uuid4();
        $site = getenv('SU_TEST_SITE') ?: dirname(__DIR__, 2) . '/.work/site';
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__) . '/lib/child.php', $site, $barrier], $command);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot spawn child process');
        }

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int, resource>} $p
     *
     * @return array<string, mixed>
     */
    private function collect(array $p): array
    {
        $out = stream_get_contents($p[1][1]);
        $err = stream_get_contents($p[1][2]);
        proc_close($p[0]);
        $json = json_decode((string) $out, true);

        return is_array($json) ? $json : ['ok' => false, 'code' => 'child_crashed', 'output' => substr((string) $out . $err, 0, 500)];
    }

    private function rawConnection(): \mysqli
    {
        [$host, $port] = explode(':', DB_HOST) + [1 => 3306];
        $link = new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, (int) $port);
        $link->set_charset('utf8mb4');

        return $link;
    }
}
