<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Redis\Limiters\DurationLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Scenarios\RedisContract;
use Tests\Support\Redis\Eventually;
use Tests\Support\Redis\RedisKeys;

/*
 * ADR-0022 §7: rate limiting on Redis.
 *
 * - Laravel's RateLimiter on the redis cache store. hit() is an atomic
 *   server-side increment, so a limit enforced on its return value is exact
 *   under concurrency. RateLimiter::attempt() is check-then-hit and is not
 *   atomic on any store; it is not relied on here.
 * - Redis::throttle()'s DurationLimiter, a single Lua script: atomic
 *   admission per window.
 */

function limiterKey(string $name): string
{
    $key = $name.':'.bin2hex(random_bytes(6));
    $prefix = Cache::store('redis')->getPrefix();

    RedisKeys::track($prefix.$key, 'cache');
    RedisKeys::track($prefix.$key.':timer', 'cache');

    return $key;
}

function limiter(): RateLimiter
{
    return new RateLimiter(Cache::store('redis'));
}

it('counts hits, blocks at the limit and clears', function (): void {
    $key = limiterKey('limit:basic');
    $limiter = limiter();

    foreach (range(1, 3) as $expected) {
        expect($limiter->tooManyAttempts($key, 3))->toBeFalse()
            ->and($limiter->hit($key, 60))->toBe($expected);
    }

    expect($limiter->tooManyAttempts($key, 3))->toBeTrue()
        ->and((int) $limiter->attempts($key))->toBe(3)
        ->and($limiter->remaining($key, 3))->toBe(0)
        ->and($limiter->availableIn($key))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $limiter->clear($key);

    expect((int) $limiter->attempts($key))->toBe(0)
        ->and($limiter->tooManyAttempts($key, 3))->toBeFalse();
});

it('opens again when the window expires on the server', function (): void {
    $key = limiterKey('limit:window');
    $limiter = limiter();

    $limiter->hit($key, 1);
    $limiter->hit($key, 1);

    expect($limiter->tooManyAttempts($key, 2))->toBeTrue();

    Eventually::value(
        static fn (): bool => $limiter->tooManyAttempts($key, 2),
        static fn (bool $blocked): bool => ! $blocked,
        5.0,
        'the rate-limit window to expire',
    );

    expect((int) $limiter->attempts($key))->toBe(0)
        ->and($limiter->hit($key, 60))->toBe(1);
});

it('counts every concurrent hit exactly once across four processes once the window is open', function (): void {
    $key = limiterKey('limit:concurrent');

    // RateLimiter::increment() is add(key, 0), INCRBY, then put(key, amount)
    // when the add lost and the count equals the amount. The first hits of
    // a new window can race through that put and lose counts (Laravel
    // behaviour, measured in the PR). Once the window exists, every hit is a
    // plain atomic INCRBY. Use Redis::throttle() when the start of the
    // window must be exact too (next tests).
    expect(limiter()->hit($key, 60))->toBe(1);

    $results = Concurrently::run($this->app, RedisContract::class, 4, ['op' => 'rate-hit', 'key' => $key, 'times' => 25]);

    $hits = array_merge(...array_map(static fn ($r): array => $r->get('hits'), $results));
    sort($hits);

    // Admitting a request only while hit() <= 10 would admit exactly 9 more.
    expect($hits)->toBe(range(2, 101))
        ->and(count(array_filter($hits, static fn (int $n): bool => $n <= 10)))->toBe(9)
        ->and((int) limiter()->attempts($key))->toBe(101);
});

it('admits exactly the throttle limit from four concurrent processes', function (): void {
    $key = 'throttle:concurrent:'.bin2hex(random_bytes(6));
    RedisKeys::track($key);

    $results = Concurrently::run($this->app, RedisContract::class, 4, ['op' => 'throttle', 'key' => $key, 'max' => 7, 'times' => 5]);

    $admitted = array_merge(...array_map(static fn ($r): array => $r->get('admitted'), $results));

    expect(count(array_filter($admitted)))->toBe(7)
        ->and(count($admitted))->toBe(20);
});

it('re-admits after the throttle window expires on the server', function (): void {
    $key = 'throttle:window:'.bin2hex(random_bytes(6));
    RedisKeys::track($key);
    $limiter = new DurationLimiter(Redis::connection(), $key, 1, 1);

    expect($limiter->acquire())->toBeTrue()
        ->and($limiter->acquire())->toBeFalse();

    expect(Eventually::value(static fn (): bool => $limiter->acquire(), static fn (bool $ok): bool => $ok, 5.0, 'the throttle window to expire'))
        ->toBeTrue();
});
