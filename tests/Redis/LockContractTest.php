<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\WorkerFailed;
use Tests\Concurrency\Scenarios\RedisContract;
use Tests\Support\Redis\Eventually;
use Tests\Support\Redis\RedisKeys;

/*
 * ADR-0022 §7: Laravel's Redis locks (Cache::lock on the redis store) across
 * independent processes. Every contention case uses separate worker
 * processes with their own connections; none relies on two lock objects
 * sharing one connection.
 */

/**
 * @return array{0: string, 1: string} The lock name and its full key.
 */
function lockKey(string $name): array
{
    $key = $name.':'.bin2hex(random_bytes(6));
    $prefix = Cache::store('redis')->getPrefix();

    RedisKeys::track($prefix.$key);

    return [$key, 'nexo_testing:'.$prefix.$key];
}

function lockOwnerOnServer(string $fullKey): string|false
{
    $value = Redis::connection()->client()->rawCommand('GET', $fullKey);

    return is_string($value) ? $value : false;
}

it('lets exactly one of four processes take the lock', function (): void {
    [$key, $full] = lockKey('lock:race');

    $results = Concurrently::run($this->app, RedisContract::class, 4, ['op' => 'lock', 'key' => $key]);
    $holders = array_values(array_filter($results, static fn ($r): bool => $r->get('acquired') === true));

    expect($holders)->toHaveCount(1)
        ->and(lockOwnerOnServer($full))->toBe($holders[0]->get('owner'));
});

it('releases only for the owner', function (): void {
    [$key, $full] = lockKey('lock:owner');

    [$result] = Concurrently::run($this->app, RedisContract::class, 1, ['op' => 'lock', 'key' => $key]);
    $owner = (string) $result->get('owner');

    $stranger = Cache::store('redis')->lock($key, 60);

    expect($result->get('acquired'))->toBeTrue()
        ->and($stranger->release())->toBeFalse()
        ->and(lockOwnerOnServer($full))->toBe($owner)
        ->and(Cache::store('redis')->restoreLock($key, $owner)->release())->toBeTrue()
        ->and(lockOwnerOnServer($full))->toBeFalse();
});

it('hands the lock to a process blocking on it only after the holder releases', function (): void {
    [$key] = lockKey('lock:handover');

    [$holder, $waiter] = Concurrently::run($this->app, RedisContract::class, 2, ['op' => 'lock-handover', 'key' => $key]);

    expect($holder->get('acquired'))->toBeTrue()
        ->and($holder->get('released'))->toBeTrue()
        ->and($waiter->get('acquired'))->toBeTrue()
        ->and($waiter->get('acquired_at'))->toBeGreaterThanOrEqual($holder->get('released_at'));
});

it('expires a lock on the server', function (): void {
    [$key, $full] = lockKey('lock:expiry');

    expect(Cache::store('redis')->lock($key, 1)->get())->toBeTrue();

    $pttl = (int) Redis::connection()->client()->rawCommand('PTTL', $full);
    expect($pttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(1000);

    $acquired = Eventually::value(
        static fn (): bool => Cache::store('redis')->lock($key, 60)->get(),
        static fn (bool $got): bool => $got,
        5.0,
        'the expired lock to become available',
    );

    expect($acquired)->toBeTrue();
});

it('recovers a lock whose holder died without releasing it', function (): void {
    [$key, $full] = lockKey('lock:crash');

    expect(fn () => Concurrently::run($this->app, RedisContract::class, 1, ['op' => 'lock-crash', 'key' => $key, 'seconds' => 2]))
        ->toThrow(WorkerFailed::class);

    // The dead process still holds it: nobody else can take it yet.
    expect(lockOwnerOnServer($full))->toBeString()->not->toBe('')
        ->and(Cache::store('redis')->lock($key, 60)->get())->toBeFalse();

    $acquired = Eventually::value(
        static fn (): bool => Cache::store('redis')->lock($key, 60)->get(),
        static fn (bool $got): bool => $got,
        8.0,
        'the dead holder\'s lock to expire',
    );

    expect($acquired)->toBeTrue();
});
