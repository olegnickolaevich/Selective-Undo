<?php

declare(strict_types=1);

/**
 * Test entry point.
 *
 *   php tests/run.php unit                     domain tests, no WordPress required
 *   php tests/run.php integration [filter]     requires bin/test-env.sh up
 *   php tests/run.php all
 */

$suite = $argv[1] ?? 'all';
$filter = $argv[2] ?? null;
$root = dirname(__DIR__);

require_once __DIR__ . '/lib/AssertionFailed.php';
require_once __DIR__ . '/lib/TestCase.php';
require_once __DIR__ . '/lib/Runner.php';

$runner = new SelectiveUndo\Tests\Runner($filter);

if ($suite === 'unit' || $suite === 'all') {
    require_once $root . '/src/autoload.php';
    echo "Unit tests\n";
    $runner->runDirectory(__DIR__ . '/unit');
}

if ($suite === 'integration' || $suite === 'all') {
    $site = getenv('SU_TEST_SITE') ?: $root . '/.work/site';

    if (!is_file($site . '/wp-load.php')) {
        fwrite(STDERR, "Test site not found. Run: bin/test-env.sh up\n");
        exit(2);
    }

    $_SERVER['HTTP_HOST'] = '127.0.0.1:8899';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['SERVER_NAME'] = '127.0.0.1';
    require $site . '/wp-load.php';
    require_once __DIR__ . '/lib/IntegrationTestCase.php';
    echo "\nIntegration tests (WordPress " . get_bloginfo('version') . ")\n";
    $runner->runDirectory(__DIR__ . '/integration');
}

exit($runner->report());
