<?php

declare(strict_types=1);

/**
 * Child process for concurrency tests: loads WordPress, waits on a barrier file,
 * (variables are prefixed because wp-load.php runs in this global scope)
 * runs an action and prints a JSON result.
 *
 *   php child.php <site> <barrier-file> <action> [args...]
 */

[, $suSite, $suBarrier, $suAction] = $argv;
$suArgs = array_slice($argv, 4);
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . (getenv('SU_E2E_PORT') ?: '8899');
$_SERVER['REQUEST_URI'] = '/';
require $suSite . '/wp-load.php';
wp_set_current_user(1);
$s = SelectiveUndo\Bootstrap\Plugin::services();

$deadline = microtime(true) + 20;

while (!file_exists($suBarrier) && microtime(true) < $deadline) {
    usleep(2000);
}

try {
    $result = match ($suAction) {
        'create_job' => $s->createJob()->handle(1, $suArgs[0], $suArgs[1]),
        'run_job' => ['finished' => $s->executeJob()->run((int) $suArgs[0])],
        default => throw new RuntimeException('unknown action'),
    };
    echo json_encode(['ok' => true, 'result' => $result]);
} catch (SelectiveUndo\Domain\DomainError $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => get_class($e), 'message' => $e->getMessage()]);
}
