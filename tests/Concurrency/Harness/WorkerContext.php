<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use RuntimeException;

/**
 * File-based signalling inside one run's private directory: the start
 * barrier, and named signals for scenarios that need a fixed order between
 * workers instead of sleeps.
 */
final readonly class WorkerContext
{
    private const int POLL_MICROSECONDS = 200;

    public function __construct(public string $runDirectory, public int $worker) {}

    public function signal(string $name): void
    {
        self::assertName($name);

        if (file_put_contents($this->runDirectory.'/signal-'.$name, (string) microtime(true)) === false) {
            throw new RuntimeException("Could not write signal '{$name}'.");
        }
    }

    public function waitFor(string $name, float $timeoutSeconds = 10.0): void
    {
        self::assertName($name);

        $this->waitForFile($this->runDirectory.'/signal-'.$name, $timeoutSeconds, "signal '{$name}'");
    }

    public function waitForFile(string $path, float $timeoutSeconds, string $what): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Worker {$this->worker} timed out after {$timeoutSeconds}s waiting for {$what}.");
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    private static function assertName(string $name): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            throw new RuntimeException("Invalid signal name '{$name}'.");
        }
    }
}
