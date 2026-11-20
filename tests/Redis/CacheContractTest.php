<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Scenarios\RedisContract;
use Tests\Support\Redis\Eventually;
use Tests\Support\Redis\RedisKeys;

/*
 * ADR-0022 §7: Laravel's Redis cache store on the restricted nexo_test
 * identity. Cross-process cases use the concurrency harness: separate PHP
 * processes, each with its own application and connection, released
 * together by a start barrier. TTLs are asserted on what the server reports
 * (PTTL) and expiry by bounded polling, never by the length of a sleep.
 */

/**
 * The cache key, recorded for clean-up, and its full name on the server.
 *
 * @return array{0: string, 1: string}
 */
function cacheKey(string $name): array
{
    $key = $name.':'.bin2hex(random_bytes(6));
    $prefix = Cache::store('redis')->getPrefix();

    RedisKeys::track($prefix.$key, 'cache');

    return [$key, 'nexo_testing:'.$prefix.$key];
}

function serverPttl(string $fullKey): int
{
    return (int) Redis::connection('cache')->client()->rawCommand('PTTL', $fullKey);
}

it('stores, reads and deletes a value', function (): void {
    [$key, $full] = cacheKey('cache:crud');
    $cache = Cache::store('redis');

    expect($cache->get($key))->toBeNull()
        ->and($cache->put($key, 'value', 60))->toBeTrue()
        ->and($cache->get($key))->toBe('value')
        ->and(Redis::connection('cache')->client()->rawCommand('EXISTS', $full))->toBe(1)
        ->and($cache->forget($key))->toBeTrue()
        ->and($cache->get($key))->toBeNull();
});

it('keeps every cache key inside the test namespace', function (): void {
    [$key, $full] = cacheKey('cache:namespace');

    Cache::store('redis')->put($key, 'value', 60);

    expect($full)->toStartWith('nexo_testing:')
        ->and(serverPttl($full))->toBeGreaterThan(0)
        // The same name without the connection prefix is outside the ACL.
        ->and(fn () => Redis::connection('cache')->client()->rawCommand('GET', Cache::store('redis')->getPrefix().$key))
        ->toThrow(RedisException::class, 'NOPERM No permissions to access a key');
});

it('sets the TTL on the server and lets the server expire the value', function (): void {
    [$key, $full] = cacheKey('cache:ttl');
    $cache = Cache::store('redis');

    $cache->put($key, 'short-lived', 1);

    expect(serverPttl($full))->toBeGreaterThan(0)->toBeLessThanOrEqual(1000);

    Eventually::value(static fn (): mixed => $cache->get($key), static fn (mixed $v): bool => $v === null, 5.0, 'the value to expire');

    expect(serverPttl($full))->toBe(-2);
});

it('stores a value forever with no TTL', function (): void {
    [$key, $full] = cacheKey('cache:forever');

    Cache::store('redis')->forever($key, 'kept');

    expect(serverPttl($full))->toBe(-1);
});

it('adds only when the key is absent, keeping the first value and its TTL', function (): void {
    [$key, $full] = cacheKey('cache:add');
    $cache = Cache::store('redis');

    expect($cache->add($key, 'first', 60))->toBeTrue()
        ->and($cache->add($key, 'second', 60))->toBeFalse()
        ->and($cache->get($key))->toBe('first')
        ->and(serverPttl($full))->toBeGreaterThan(0);
});

it('increments and decrements atomically on the server', function (): void {
    [$key] = cacheKey('cache:counter');
    $cache = Cache::store('redis');

    expect($cache->increment($key))->toBe(1)
        ->and($cache->increment($key, 5))->toBe(6)
        ->and($cache->decrement($key, 2))->toBe(4)
        ->and($cache->decrement($key, 10))->toBe(-6)
        ->and((int) $cache->get($key))->toBe(-6);
});

it('loses no increment when four processes increment at once', function (): void {
    [$key] = cacheKey('cache:concurrent-counter');

    $results = Concurrently::run($this->app, RedisContract::class, 4, ['op' => 'increment', 'key' => $key, 'times' => 50]);

    $values = array_merge(...array_map(static fn ($r): array => $r->get('values'), $results));
    sort($values);

    // Every increment returned a distinct value, 1..200: none was lost or
    // duplicated, so the read-modify-write happened on the server.
    expect($values)->toBe(range(1, 200))
        ->and((int) Cache::store('redis')->get($key))->toBe(200);
});

it('lets exactly one of four processes add the same key', function (): void {
    [$key] = cacheKey('cache:concurrent-add');

    $results = Concurrently::run($this->app, RedisContract::class, 4, ['op' => 'add', 'key' => $key]);
    $winners = array_values(array_filter($results, static fn ($r): bool => $r->get('added') === true));

    expect($winners)->toHaveCount(1)
        ->and(Cache::store('redis')->get($key))->toBe('worker-'.$winners[0]->worker);
});

it('reads in this process a value another process wrote', function (): void {
    [$key] = cacheKey('cache:cross-process');
    $value = bin2hex(random_bytes(8));

    Concurrently::run($this->app, RedisContract::class, 1, ['op' => 'put', 'key' => $key, 'value' => $value]);

    expect(Cache::store('redis')->get($key))->toBe($value);
});
