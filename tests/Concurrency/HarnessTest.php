<?php

declare(strict_types=1);

use Tests\Concurrency\Harness\ConcurrencyTimeout;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\WorkerFailed;
use Tests\Concurrency\Harness\WorkerResult;
use Tests\Concurrency\Scenarios\HarnessProbe;

/*
 * The harness itself: separate processes, a real start barrier, a timeout
 * that kills, and failures that surface.
 */

it('runs every worker in its own process', function (): void {
    $results = Concurrently::run(app(), HarnessProbe::class, workers: 3);

    $pids = array_map(fn (WorkerResult $r): mixed => $r->get('pid'), $results);

    expect(array_unique($pids))->toHaveCount(3)
        ->and($pids)->not->toContain(getmypid());
});

it('releases no worker before every worker is ready', function (): void {
    $results = Concurrently::run(app(), HarnessProbe::class, workers: 4);

    $lastReady = max(array_map(fn (WorkerResult $r): float => $r->readyAt, $results));
    $firstReleased = min(array_map(fn (WorkerResult $r): float => $r->releasedAt, $results));

    expect($lastReady)->toBeLessThanOrEqual($firstReleased);
});

it('kills every worker and fails when the run exceeds its timeout', function (): void {
    $started = microtime(true);

    try {
        Concurrently::run(app(), HarnessProbe::class, workers: 2, arguments: ['mode' => 'sleep', 'seconds' => 60], timeout: 4.0);
        $this->fail('Expected a timeout.');
    } catch (ConcurrencyTimeout $timeout) {
        expect(microtime(true) - $started)->toBeLessThan(15.0)
            ->and($timeout->processes)->toHaveCount(2);

        foreach ($timeout->processes as $process) {
            expect($process->isRunning())->toBeFalse();
        }
    }
});

it('defaults to a 30 second timeout', function (): void {
    expect(Concurrently::DEFAULT_TIMEOUT)->toBe(30.0);
});

it('fails the run when a worker hits the test database guard', function (): void {
    expect(fn () => Concurrently::run(app(), HarnessProbe::class, workers: 2, workerEnvironment: [
        1 => ['DB_DATABASE' => 'nexo_testing_x'],
    ]))->toThrow(WorkerFailed::class, 'TEST-DB-GUARD G5');
});

it('surfaces an exception thrown inside a worker', function (): void {
    expect(fn () => Concurrently::run(app(), HarnessProbe::class, workers: 1, arguments: ['mode' => 'throw']))
        ->toThrow(WorkerFailed::class, 'scenario failure probe');
});
