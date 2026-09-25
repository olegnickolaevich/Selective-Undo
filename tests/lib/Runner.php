<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests;

/**
 * Discovers *Test.php files, runs public test* methods in isolation and reports.
 */
final class Runner
{
    private int $passed = 0;
    /** @var list<string> */
    private array $failures = [];
    private int $assertions = 0;

    public function __construct(private readonly ?string $filter = null)
    {
    }

    public function runDirectory(string $dir): void
    {
        $files = glob($dir . '/*Test.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $before = get_declared_classes();
            require_once $file;
            $classes = array_diff(get_declared_classes(), $before);

            foreach ($classes as $class) {
                $ref = new \ReflectionClass($class);

                if ($ref->isAbstract() || !$ref->isSubclassOf(TestCase::class)) {
                    continue;
                }

                foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (!str_starts_with($method->name, 'test')) {
                        continue;
                    }

                    $name = $ref->getShortName() . '::' . $method->name;

                    if ($this->filter !== null && !str_contains($name, $this->filter)) {
                        continue;
                    }

                    $this->runOne($ref, $method->name, $name);
                }
            }
        }
    }

    public function report(): int
    {
        echo "\n\n";

        foreach ($this->failures as $i => $failure) {
            echo ($i + 1) . ') ' . $failure . "\n\n";
        }

        $total = $this->passed + count($this->failures);
        printf("%s: %d tests, %d assertions, %d failures\n", $this->failures === [] ? 'OK' : 'FAILED', $total, $this->assertions, count($this->failures));

        return $this->failures === [] ? 0 : 1;
    }

    /**
     * @param \ReflectionClass<TestCase> $ref
     */
    private function runOne(\ReflectionClass $ref, string $method, string $name): void
    {
        /** @var TestCase $test */
        $test = $ref->newInstance();

        try {
            $test->setUp();

            try {
                $test->{$method}();
            } finally {
                $test->tearDown();
            }

            $this->passed++;
            echo '.';
        } catch (\Throwable $e) {
            $where = '';

            foreach ($e->getTrace() as $frame) {
                if (($frame['class'] ?? '') === $ref->getName() && ($frame['function'] ?? '') === $method) {
                    break;
                }

                if (isset($frame['file']) && str_contains($frame['file'], '/tests/') && !str_contains($frame['file'], '/tests/lib/')) {
                    $where = $frame['file'] . ':' . ($frame['line'] ?? 0);
                    break;
                }
            }

            if ($where === '' && !($e instanceof AssertionFailed)) {
                $where = $e->getFile() . ':' . $e->getLine();
            }

            $this->failures[] = $name . ($where !== '' ? ' (' . $where . ')' : '') . "\n   " . ($e instanceof AssertionFailed ? '' : $e::class . ': ') . $e->getMessage();
            echo 'F';
        }

        $this->assertions += $test->assertions;
    }
}
