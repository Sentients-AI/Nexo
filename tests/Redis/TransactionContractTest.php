<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Tests\Support\Redis\RedisKeys;

/*
 * ADR-0022 §6, §7: MULTI/EXEC on the restricted identity.
 *
 * EXEC is granted because Laravel needs it (RedisStore::putMany(); see
 * CacheContractTest). That is safe only because the ACL checks every command
 * when it is queued: a refused command poisons the transaction, EXEC then
 * aborts it as a whole, and none of its queued writes run. These cases
 * prove that, and that a clean transaction does run.
 */

/**
 * @param  list<list<string>>  $commands  Queued between MULTI and EXEC.
 * @return array{queued: list<string>, exec: mixed, error: ?string}
 */
function transaction(array $commands): array
{
    $client = Redis::connection()->client();
    $client->clearLastError();

    expect($client->rawCommand('MULTI'))->toBeTrue();

    $queued = [];

    foreach ($commands as $command) {
        try {
            $queued[] = var_export($client->rawCommand(...$command), true);
        } catch (RedisException $e) {
            $queued[] = $e->getMessage();
        }
    }

    try {
        $exec = $client->rawCommand('EXEC');
    } catch (RedisException $e) {
        return ['queued' => $queued, 'exec' => false, 'error' => $e->getMessage()];
    }

    return ['queued' => $queued, 'exec' => $exec, 'error' => $exec === false ? $client->getLastError() : null];
}

it('positive control: a transaction of permitted commands runs', function (): void {
    $a = RedisKeys::track('tx:clean:a');
    $b = RedisKeys::track('tx:clean:b');

    $run = transaction([['SET', 'nexo_testing:'.$a, '1'], ['SET', 'nexo_testing:'.$b, '2']]);

    expect($run['error'])->toBeNull()
        ->and($run['exec'])->toBe([true, true])
        ->and(Redis::connection()->get($a))->toBe('1')
        ->and(Redis::connection()->get($b))->toBe('2');
});

it('aborts a whole transaction that queues a prohibited command, running none of its writes', function (array $prohibited, string $rule): void {
    $before = RedisKeys::track('tx:poisoned:before');
    $after = RedisKeys::track('tx:poisoned:after');
    $canary = RedisKeys::track('tx:poisoned:canary');
    Redis::connection()->set($canary, 'alive');

    $run = transaction([
        ['SET', 'nexo_testing:'.$before, 'ran'],
        $prohibited,
        ['SET', 'nexo_testing:'.$after, 'ran'],
    ]);

    expect($run['queued'][0])->toBe('true')
        ->and($run['queued'][1])->toStartWith("NOPERM User nexo_test has no permissions to run the '{$rule}' command")
        ->and($run['error'])->toStartWith('EXECABORT Transaction discarded because of previous errors')
        ->and(Redis::connection()->get($before))->toBeNull()
        ->and(Redis::connection()->get($after))->toBeNull()
        ->and(Redis::connection()->get($canary))->toBe('alive');
})->with([
    'FLUSHDB' => [['FLUSHDB'], 'flushdb'],
    'FLUSHALL' => [['FLUSHALL'], 'flushall'],
    'SELECT' => [['SELECT', '1'], 'select'],
    'SWAPDB' => [['SWAPDB', '0', '1'], 'swapdb'],
    'COPY into another database' => [['COPY', 'nexo_testing:tx:a', 'nexo_testing:tx:b', 'DB', '1'], 'copy'],
    'KEYS' => [['KEYS', '*'], 'keys'],
    'CONFIG SET' => [['CONFIG', 'SET', 'maxmemory', '1'], 'config|set'],
]);

it('aborts a whole transaction that queues a key outside the namespace', function (): void {
    $inside = RedisKeys::track('tx:outside:inside');

    $run = transaction([
        ['SET', 'nexo_testing:'.$inside, 'ran'],
        ['SET', 'nexo_outside:tx', 'ran'],
    ]);

    expect($run['queued'][1])->toBe('NOPERM No permissions to access a key')
        ->and($run['error'])->toStartWith('EXECABORT')
        ->and(Redis::connection()->get($inside))->toBeNull();
});

it('leaves the connection usable after an aborted transaction', function (): void {
    transaction([['FLUSHDB']]);

    $key = RedisKeys::track('tx:after-abort');

    expect(Redis::connection()->set($key, 'ok'))->toBeTrue()
        ->and(Redis::connection()->get($key))->toBe('ok');
});
