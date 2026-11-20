<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Redis\GuardedRedisManager;

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

it('keeps the guarded manager after the application is rebooted', function (): void {
    $this->rebootSimulatingEnvironment('testing');

    expect(app('redis'))->toBeInstanceOf(GuardedRedisManager::class);
});
