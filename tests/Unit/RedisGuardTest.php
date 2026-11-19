<?php

declare(strict_types=1);

use Tests\Support\Database\GuardInput;
use Tests\Support\Database\TestDatabaseGuard;

/*
 * ADR-0022 §5: the pure Redis rules R1-R5, proven without a server.
 * tests/Redis/GuardFailsClosedTest.php proves they, and the live rule R6,
 * stop a real run before any command.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function safeRedisConnection(array $overrides = []): array
{
    return array_merge([
        'url' => '',
        'host' => '127.0.0.1',
        'username' => 'nexo_test',
        'password' => 'nexo_test',
        'port' => '6379',
        'database' => null,
        'prefix' => 'nexo_testing:',
        'cluster' => 'redis',
    ], $overrides);
}

/**
 * @param  array<string, array<string, mixed>>  $redis
 * @return list<string> The rule ids of the violations.
 */
function redisRuleIds(array $redis, bool $clusters = false): array
{
    $input = new GuardInput(
        configCached: false,
        configCachePath: '/app/bootstrap/cache/config.php',
        appEnv: 'testing',
        defaultConnection: 'mysql',
        connections: ['mysql' => [
            'driver' => 'mysql', 'url' => '', 'host' => '127.0.0.1', 'database' => 'nexo_testing', 'unix_socket' => '',
        ]],
        redis: $redis,
        redisClustersConfigured: $clusters,
    );

    return array_values(array_unique(array_map(
        static fn (string $violation): string => substr($violation, 0, 2),
        TestDatabaseGuard::violations($input),
    )));
}

it('accepts the safe Redis test configuration', function (): void {
    expect(redisRuleIds(['default' => safeRedisConnection(), 'cache' => safeRedisConnection()]))->toBe([]);
});

it('is not evaluated before the configuration is loaded', function (): void {
    expect(TestDatabaseGuard::violations(new GuardInput(false, null, 'testing')))->toBe([]);
});

it('checks every connection, not only default and cache', function (): void {
    expect(redisRuleIds([
        'default' => safeRedisConnection(),
        'horizon' => safeRedisConnection(['host' => '10.0.0.5']),
    ]))->toBe(['R1']);
});

it('R1 rejects a host outside the allowlist', function (mixed $host): void {
    expect(redisRuleIds(['default' => safeRedisConnection(['host' => $host])]))->toBe(['R1']);
})->with([
    'another loopback address' => '127.0.0.2',
    'a remote host' => 'redis.internal',
    'a container hostname' => 'valkey',
    'missing' => null,
    'a list' => [['127.0.0.1']],
]);

it('R1 accepts each allowlisted host', function (string $host): void {
    expect(redisRuleIds(['default' => safeRedisConnection(['host' => $host])]))->toBe([]);
})->with(['127.0.0.1', 'localhost', '::1']);

it('R2 rejects a URL, a TLS scheme or context, and clusters', function (array $overrides, bool $clusters): void {
    expect(redisRuleIds(['default' => safeRedisConnection($overrides)], $clusters))->toBe(['R2']);
})->with([
    'URL' => [['url' => 'redis://127.0.0.1:6379'], false],
    'TLS scheme' => [['scheme' => 'tls'], false],
    'stream context' => [['context' => ['verify_peer' => false]], false],
    'cluster name' => [['cluster_name' => 'nexo'], false],
    'clusters configured' => [[], true],
]);

it('R3 requires the nexo_test identity', function (mixed $username): void {
    expect(redisRuleIds(['default' => safeRedisConnection(['username' => $username])]))->toBe(['R3']);
})->with([
    'the default user' => null,
    'empty' => '',
    'the admin user' => 'nexo_ci_admin',
    'a look-alike' => 'nexo_test ',
]);

it('R4 requires the nexo_testing: namespace', function (mixed $prefix): void {
    expect(redisRuleIds(['default' => safeRedisConnection(['prefix' => $prefix])]))->toBe(['R4']);
})->with([
    'none' => null,
    'empty' => '',
    'Laravel\'s default' => 'laravel-database-',
    'without the separator' => 'nexo_testing',
    'a look-alike' => 'nexo_testingX:',
    'a sub-namespace' => 'nexo_testing:other:',
    'test token zero' => 'nexo_testing:test_0:',
    'a trailing newline' => "nexo_testing:\n",
]);

it('R4 accepts a parallel worker namespace', function (): void {
    expect(redisRuleIds(['default' => safeRedisConnection(['prefix' => 'nexo_testing:test_3:'])]))->toBe([]);
});

it('R5 requires the database to be unset, because Laravel would SELECT it', function (mixed $database): void {
    expect(redisRuleIds(['default' => safeRedisConnection(['database' => $database])]))->toBe(['R5']);
})->with([
    'database 0 as a string' => '0',
    'database 0' => 0,
    'database 1' => '1',
]);

it('reports every rule a connection breaks', function (): void {
    expect(redisRuleIds(['default' => safeRedisConnection([
        'host' => 'redis.internal',
        'url' => 'redis://redis.internal',
        'username' => null,
        'prefix' => '',
        'database' => '0',
    ])]))->toBe(['R1', 'R2', 'R3', 'R4', 'R5']);
});
