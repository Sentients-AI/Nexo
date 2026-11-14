<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;

/**
 * Exercises the harness itself.
 *
 * Arguments: mode = "return" | "sleep" (seconds) | "throw".
 */
final class HarnessProbe implements Scenario
{
    public function prepare(Application $app, int $worker, array $arguments): void {}

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        return match ($arguments['mode'] ?? 'return') {
            'sleep' => (function () use ($arguments): array {
                sleep((int) $arguments['seconds']);

                return ['slept' => true];
            })(),
            'throw' => throw new RuntimeException('scenario failure probe'),
            default => ['pid' => getmypid()],
        };
    }
}
