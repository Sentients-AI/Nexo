<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Support\ChildProcess;
use Tests\Support\Redis\RedisGate;

/*
 * ADR-0022 §4: under --parallel, each worker gets its own Redis namespace,
 * nexo_testing:test_<N>:, so Unit and Feature tests that use Redis cannot
 * collide. (The Redis suite itself never runs in parallel.)
 *
 * Two children present themselves as parallel workers 1 and 2, run at the
 * same time, and write the same logical keys, directly and through the Redis
 * cache store. Each must land under its own prefix, inside nexo_testing:*,
 * with nothing written under the shared base namespace.
 */

function rawValue(string $fullKey): string|false
{
    $value = Redis::connection()->client()->rawCommand('GET', $fullKey);

    return is_string($value) ? $value : false;
}

/**
 * @return array{exit: ?int, output: string, prefix: ?string, cachePrefix: ?string}
 */
function workerRun(Process $process): array
{
    $output = $process->getOutput().$process->getErrorOutput();

    preg_match('/^NEXO-PREFIX (\S+)$/m', $output, $prefix);
    preg_match('/^NEXO-CACHE-PREFIX (\S+)$/m', $output, $cachePrefix);

    return [
        'exit' => $process->getExitCode(),
        'output' => $output,
        'prefix' => $prefix[1] ?? null,
        'cachePrefix' => $cachePrefix[1] ?? null,
    ];
}

function parallelWorker(string $token, array $overrides = []): Process
{
    return ChildProcess::php(
        ['vendor/bin/pest', 'tests/Probes/ParallelRedisProbeTest.php', '--colors=never'],
        array_merge(['LARAVEL_PARALLEL_TESTING' => '1', 'TEST_TOKEN' => $token], $overrides),
    );
}

beforeEach(function (): void {
    $this->written = [];
});

afterEach(function (): void {
    if (! RedisGate::passed()) {
        return;
    }

    $keys = ['nexo_testing:parallel:probe', 'nexo_testing:test_1:parallel:probe', 'nexo_testing:test_2:parallel:probe', ...$this->written];

    Redis::connection()->client()->rawCommand('UNLINK', ...$keys);
});

it('gives each parallel worker its own namespace, so the same key never collides', function (): void {
    /** @var array<string, Process> $workers */
    $workers = ['1' => parallelWorker('1'), '2' => parallelWorker('2')];

    foreach ($workers as $worker) {
        $worker->start();
    }

    foreach ($workers as $token => $process) {
        $process->wait();
        $run = workerRun($process);
        $redisKey = 'nexo_testing:test_'.$token.':parallel:probe';
        $cacheKey = $run['prefix'].$run['cachePrefix'].'parallel:cache-probe';
        $this->written[] = $cacheKey;

        expect($run['exit'])->toBe(0, $run['output'])
            ->and($run['prefix'])->toBe('nexo_testing:test_'.$token.':')
            ->and(rawValue($redisKey))->toBe('worker-'.$token)
            ->and($cacheKey)->toStartWith('nexo_testing:test_'.$token.':')
            ->and((string) rawValue($cacheKey))->toContain('worker-'.$token);
    }

    expect(rawValue('nexo_testing:parallel:probe'))->toBeFalse();
});

it('refuses a worker whose configuration names another worker\'s namespace', function (): void {
    $process = parallelWorker('1', ['REDIS_PREFIX' => 'nexo_testing:test_2:']);
    $process->run();
    $run = workerRun($process);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain("TEST-DB-GUARD R4 redis connection [default] prefix 'nexo_testing:test_2:' under --parallel must be")
        ->and(rawValue('nexo_testing:test_2:parallel:probe'))->toBeFalse();
});
