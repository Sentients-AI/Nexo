<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\WorkerFailed;
use Tests\Concurrency\Scenarios\RedisContract;
use Tests\Support\Redis\GuardedRedisManager;
use Tests\Support\Redis\RedisGate;

/*
 * ADR-0022 §4: harness workers (Tests\Concurrency\Harness\Concurrently) run
 * in the parent's Redis namespace. Workers are never parallel workers
 * themselves, so a parallel parent's nexo_testing:test_<N>: is handed to
 * them explicitly; without that, workers of different parallel parents would
 * share nexo_testing: and collide.
 *
 * A parallel parent is simulated by pointing this test's application at
 * worker <N>'s namespace exactly as the parallel set-up callback does.
 */

const HARNESS_KEY = 'harness:prefix-probe';

function rawGet(string $fullKey): string|false
{
    $value = Redis::connection()->client()->rawCommand('GET', $fullKey);

    return is_string($value) ? $value : false;
}

afterEach(function (): void {
    if (! RedisGate::passed()) {
        return;
    }

    $client = Redis::connection()->client();

    foreach (['nexo_testing:', 'nexo_testing:test_7:', 'nexo_testing:test_8:', 'nexo_testing:other:'] as $prefix) {
        $client->rawCommand('UNLINK', $prefix.HARNESS_KEY);
    }
});

it('runs harness workers in the base namespace for an ordinary parent', function (): void {
    $results = Concurrently::run($this->app, RedisContract::class, 2, ['op' => 'prefix', 'key' => HARNESS_KEY]);

    foreach ($results as $result) {
        expect($result->get('prefix'))->toBe('nexo_testing:');
    }

    expect(rawGet('nexo_testing:'.HARNESS_KEY))->toStartWith('worker-');
});

it('hands a parallel parent\'s namespace to its harness workers, so different parents never collide', function (): void {
    foreach (['7', '8'] as $token) {
        GuardedRedisManager::pointAtWorker($this->app, $token);
        config(['cache.prefix' => 'nexo-cache-test_'.$token.'_']);

        $results = Concurrently::run($this->app, RedisContract::class, 2, ['op' => 'prefix', 'key' => HARNESS_KEY]);

        foreach ($results as $result) {
            expect($result->get('prefix'))->toBe('nexo_testing:test_'.$token.':')
                ->and($result->get('cache_prefix'))->toBe('nexo-cache-test_'.$token.'_');
        }
    }

    // Raw reads bypass this test's own (now test_8) prefix.
    expect(rawGet('nexo_testing:test_7:'.HARNESS_KEY))->toStartWith('worker-')
        ->and(rawGet('nexo_testing:test_8:'.HARNESS_KEY))->toStartWith('worker-')
        ->and(rawGet('nexo_testing:'.HARNESS_KEY))->toBeFalse();
});

it('refuses a harness worker handed a namespace the guard does not allow', function (string $prefix): void {
    expect(fn () => Concurrently::run($this->app, RedisContract::class, 1, ['op' => 'prefix', 'key' => HARNESS_KEY], environment: ['REDIS_PREFIX' => $prefix]))
        ->toThrow(WorkerFailed::class, 'TEST-DB-GUARD R4');

    // Only a key inside the ACL namespace can be read back at all.
    if (str_starts_with($prefix, 'nexo_testing:')) {
        expect(rawGet($prefix.HARNESS_KEY))->toBeFalse();
    }
})->with([
    'a sub-namespace' => 'nexo_testing:other:',
    'outside the ACL namespace' => 'nexo_production:',
]);
