<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Redis\GuardedRedisManager;
use Tests\Support\Redis\NegativeControls;
use Tests\Support\Redis\RedisGate;

/*
 * ADR-0022 §5: Feature and Unit tests never pass through RedisGate, so every
 * test application resolves Redis through GuardedRedisManager, which checks
 * each connection's identity before handing it out. These checks open no
 * connection, so they run everywhere. tests/Redis/UngatedResolutionTest.php
 * proves the behaviour against the real server.
 */

it('resolves Redis through the guarded manager', function (): void {
    expect(app('redis'))->toBeInstanceOf(GuardedRedisManager::class)
        ->and(Redis::getFacadeRoot())->toBe(app('redis'));
});

it('gives the Redis cache store the guarded manager', function (): void {
    $store = Cache::store('redis')->getStore();

    expect($store->getRedis())->toBe(app('redis'));
});

it('gives this process its own Redis namespace under --parallel, and the base one otherwise', function (): void {
    $token = getenv('TEST_TOKEN');
    $expected = is_string($token) && $token !== '' && getenv('LARAVEL_PARALLEL_TESTING')
        ? 'nexo_testing:test_'.$token.':'
        : 'nexo_testing:';

    // The manager copies its configuration when built; read the copy it uses.
    $managerPrefix = (fn (): mixed => $this->config['options']['prefix'] ?? null)->call(app('redis'));

    expect(config('database.redis.options.prefix'))->toBe($expected)
        ->and($managerPrefix)->toBe($expected)
        ->and(app('redis'))->toBeInstanceOf(GuardedRedisManager::class);
});

it('checks exactly the Redis connections the negative-control count assumes', function (): void {
    expect(RedisGate::connectionNames())->toBe(NegativeControls::CONNECTIONS);
});

it('keeps the guarded manager after the application is rebooted', function (): void {
    $this->rebootSimulatingEnvironment('testing');

    expect(app('redis'))->toBeInstanceOf(GuardedRedisManager::class);
});
