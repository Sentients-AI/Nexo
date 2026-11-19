<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Tests\Support\Redis\RedisKeys;

/*
 * ADR-0022 §4, §7: the test identity can touch only keys under
 * nexo_testing:. Raw commands bypass the connection prefix, so they name the
 * full key. CI also seeds nexo_ci:sentinel as nexo_ci_admin before the suite
 * and verifies it unchanged afterwards (.github/workflows/ci.yml).
 */

it('positive control: reads and writes its own namespace through the prefix', function (): void {
    $key = RedisKeys::track('isolation:probe');

    Redis::connection()->set($key, 'value');

    expect(Redis::connection()->client()->rawCommand('GET', 'nexo_testing:isolation:probe'))->toBe('value');
});

it('cannot write, read or delete a key outside its namespace', function (string ...$command): void {
    expect(fn () => Redis::connection()->client()->rawCommand(...$command))
        ->toThrow(RedisException::class, 'NOPERM No permissions to access a key');
})->with([
    'SET outside' => ['SET', 'nexo_outside:probe', 'x'],
    'GET the sentinel' => ['GET', 'nexo_ci:sentinel'],
    'SET the sentinel' => ['SET', 'nexo_ci:sentinel', 'overwritten'],
    'UNLINK the sentinel' => ['UNLINK', 'nexo_ci:sentinel'],
    'SET a prefix look-alike' => ['SET', 'nexo_testingX:probe', 'x'],
]);

it('cleans up only the keys a test recorded', function (): void {
    $connection = Redis::connection();
    $connection->set(RedisKeys::track('isolation:recorded'), '1');
    $connection->set('isolation:unrecorded', '1');

    RedisKeys::cleanUp();

    expect($connection->get('isolation:recorded'))->toBeNull()
        ->and($connection->get('isolation:unrecorded'))->toBe('1');

    $connection->command('unlink', ['isolation:unrecorded']);
});
