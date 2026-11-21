<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Tests\Support\ChildProcess;
use Tests\Support\Database\TestDatabaseGuardViolation;
use Tests\Support\Redis\GuardedRedisManager;
use Tests\Support\Redis\RedisGate;

/*
 * ADR-0022 §5 (R3, R6): Redis used outside tests/Redis, with no RedisGate,
 * still cannot run under any identity other than nexo_test.
 *
 * The child runs tests/Probes/UngatedRedisProbeTest.php, an ordinary test
 * that resolves a connection and writes a marker. The in-process cases build
 * the guarded manager with a configuration the pure guard would already
 * reject (no password), to prove the connection-time check on its own: with
 * no AUTH, the server's default user answers, or nobody does.
 */

function ungatedProbe(string $token, array $overrides): array
{
    $process = ChildProcess::php(
        ['vendor/bin/pest', 'tests/Probes/UngatedRedisProbeTest.php', '--colors=never'],
        array_merge(['NEXO_REDIS_PROBE_TOKEN' => $token], $overrides),
    );
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

function ungatedMarker(): string|false
{
    $value = Redis::connection()->client()->rawCommand('GET', 'nexo_testing:probe:ungated');

    return is_string($value) ? $value : false;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function unauthenticatedManager(array $overrides = []): GuardedRedisManager
{
    /** @var array<string, mixed> $config */
    $config = config('database.redis.default');

    return new GuardedRedisManager(app(), 'phpredis', [
        'options' => ['prefix' => 'nexo_testing:'],
        'default' => array_merge($config, $overrides),
    ]);
}

beforeEach(function (): void {
    $this->token = bin2hex(random_bytes(8));
});

afterEach(function (): void {
    if (RedisGate::passed()) {
        Redis::connection()->client()->rawCommand('UNLINK', 'nexo_testing:probe:ungated');
    }
});

it('positive control: an ungated test with the test identity writes its marker', function (): void {
    [$exit, $output] = ungatedProbe($this->token, []);

    expect($exit)->toBe(0, $output)
        ->and(ungatedMarker())->toBe($this->token);
});

it('stops an ungated test before any command', function (string $rule, array $overrides): void {
    [$exit, $output] = ungatedProbe($this->token, $overrides);

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('TEST-DB-GUARD '.$rule)
        ->and(ungatedMarker())->toBeFalse();
})->with([
    'R3 empty password' => ['R3', ['REDIS_PASSWORD' => '']],
    'R3 password unset' => ['R3', ['REDIS_PASSWORD' => 'null']],
    'R6 wrong password' => ['R6', ['REDIS_PASSWORD' => 'not-the-password']],
]);

it('refuses a connection that skipped AUTH, whatever username it was configured with', function (mixed $password): void {
    $manager = unauthenticatedManager(['password' => $password, 'username' => 'nexo_test']);

    expect(fn () => $manager->connection()->set('probe:ungated', 'written'))
        ->toThrow(TestDatabaseGuardViolation::class, 'TEST-DB-GUARD R6 redis connection [default]');

    expect(ungatedMarker())->toBeFalse();
})->with([
    'no password' => null,
    'empty password' => '',
]);
