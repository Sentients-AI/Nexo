<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Redis as PhpRedis;
use Tests\Support\Redis\RedisKeys;

/*
 * ADR-0022 §6, §7: EVAL is granted (Laravel's cache, lock, queue and
 * throttle scripts need it), so Lua must not widen what nexo_test can do.
 *
 * Valkey checks each command a script runs against the caller's ACL, with
 * its real key arguments, so these cases prove it rather than assume it:
 * keys built inside the script, keys passed as ARGV, declared keys outside
 * the namespace, and every prohibited command. phpredis prefixes the KEYS
 * of EVAL with the connection prefix but not ARGV; a script that takes a key
 * from ARGV therefore names a key outside nexo_testing: and is refused.
 *
 * phpredis returns false (not an exception) when a script fails, so the
 * error is read from the client.
 */

/**
 * @param  Closure(PhpRedis): mixed  $call
 * @return array{reply: mixed, error: ?string}
 */
function luaRun(Closure $call): array
{
    $client = Redis::connection()->client();
    $client->clearLastError();

    try {
        $reply = $call($client);
    } catch (RedisException $e) {
        return ['reply' => false, 'error' => $e->getMessage()];
    }

    return ['reply' => $reply, 'error' => $reply === false ? $client->getLastError() : null];
}

function luaCanary(): string
{
    $key = RedisKeys::track('lua:canary:'.bin2hex(random_bytes(4)));
    Redis::connection()->set($key, 'alive');

    return $key;
}

it('prefixes the KEYS of EVAL but not ARGV', function (): void {
    $connection = Redis::connection();

    expect($connection->eval('return KEYS[1]', 1, 'lua:key', 'lua:argv'))->toBe('nexo_testing:lua:key')
        ->and($connection->eval('return ARGV[1]', 1, 'lua:key', 'lua:argv'))->toBe('lua:argv');
});

it('positive control: a script may write the key it declares', function (): void {
    $key = RedisKeys::track('lua:own');

    expect(Redis::connection()->eval("return redis.call('SET', KEYS[1], ARGV[1])", 1, $key, 'written'))->toBeTrue()
        ->and(Redis::connection()->get($key))->toBe('written');
});

it('refuses a key built inside the script outside the namespace', function (): void {
    $run = luaRun(static fn (PhpRedis $r): mixed => $r->rawCommand('EVAL', "return redis.call('SET', 'nexo_outside:lua', 'x')", '0'));

    expect($run['reply'])->toBeFalse()
        ->and($run['error'])->toStartWith('ERR ACL failure in script: No permissions to access a key');
});

it('refuses a key passed as ARGV, which phpredis does not prefix', function (): void {
    $run = luaRun(static fn (PhpRedis $r): mixed => Redis::connection()->eval("return redis.call('SET', ARGV[1], 'x')", 1, 'lua:declared', 'lua:argv'));

    expect($run['reply'])->toBeFalse()
        ->and($run['error'])->toStartWith('ERR ACL failure in script: No permissions to access a key');
});

it('refuses a declared key outside the namespace before the script runs', function (): void {
    $run = luaRun(static fn (PhpRedis $r): mixed => $r->rawCommand('EVAL', "return redis.call('SET', KEYS[1], 'x')", '1', 'nexo_outside:declared'));

    expect($run['error'])->toBe('NOPERM No permissions to access a key');
});

it('refuses a prohibited command run from a script', function (array $call, string $refusal): void {
    $canary = luaCanary();
    $script = 'return redis.call('.implode(', ', array_map(static fn (string $a): string => str_starts_with($a, 'KEYS[') ? $a : "'".$a."'", $call)).')';

    $run = luaRun(static fn (PhpRedis $r): mixed => $r->rawCommand('EVAL', $script, '2', 'nexo_testing:lua:a', 'nexo_testing:lua:b'));

    expect($run['reply'])->toBeFalse()
        ->and($run['error'])->toContain($refusal)
        ->and(Redis::connection()->get($canary))->toBe('alive');
})->with([
    'FLUSHDB' => [['FLUSHDB'], "ACL failure in script: User nexo_test has no permissions to run the 'flushdb' command"],
    'FLUSHALL' => [['FLUSHALL'], "ACL failure in script: User nexo_test has no permissions to run the 'flushall' command"],
    'SWAPDB' => [['SWAPDB', '0', '1'], "ACL failure in script: User nexo_test has no permissions to run the 'swapdb' command"],
    'SELECT' => [['SELECT', '1'], "ACL failure in script: User nexo_test has no permissions to run the 'select' command"],
    'COPY into another database' => [['COPY', 'KEYS[1]', 'KEYS[2]', 'DB', '1'], "ACL failure in script: User nexo_test has no permissions to run the 'copy' command"],
    'MOVE' => [['MOVE', 'KEYS[1]', '1'], "ACL failure in script: User nexo_test has no permissions to run the 'move' command"],
    'RENAME' => [['RENAME', 'KEYS[1]', 'KEYS[2]'], "ACL failure in script: User nexo_test has no permissions to run the 'rename' command"],
    'KEYS' => [['KEYS', '*'], "ACL failure in script: User nexo_test has no permissions to run the 'keys' command"],
    'SCAN' => [['SCAN', '0'], "ACL failure in script: User nexo_test has no permissions to run the 'scan' command"],
    'CONFIG GET' => [['CONFIG', 'GET', 'maxmemory'], 'This Redis command is not allowed from script'],
    'CONFIG SET' => [['CONFIG', 'SET', 'maxmemory', '1'], 'This Redis command is not allowed from script'],
    'ACL SETUSER' => [['ACL', 'SETUSER', 'nexo_test', '+@all'], 'This Redis command is not allowed from script'],
    'SCRIPT FLUSH' => [['SCRIPT', 'FLUSH'], 'This Redis command is not allowed from script'],
    'FUNCTION FLUSH' => [['FUNCTION', 'FLUSH'], 'This Redis command is not allowed from script'],
    'SHUTDOWN' => [['SHUTDOWN', 'NOSAVE'], 'This Redis command is not allowed from script'],
    'MULTI' => [['MULTI'], 'This Redis command is not allowed from script'],
    'EXEC' => [['EXEC'], 'This Redis command is not allowed from script'],
]);

it('keeps the ACL in force after a script tried to change it', function (): void {
    luaRun(static fn (PhpRedis $r): mixed => $r->rawCommand('EVAL', "return redis.call('ACL', 'SETUSER', 'nexo_test', '+@all', '~*')", '0'));

    expect(fn () => Redis::connection()->client()->rawCommand('GET', 'nexo_outside:after-lua'))
        ->toThrow(RedisException::class, 'NOPERM No permissions to access a key')
        ->and(fn () => Redis::connection()->client()->rawCommand('FLUSHDB'))
        ->toThrow(RedisException::class, "NOPERM User nexo_test has no permissions to run the 'flushdb' command");
});

it('reports a refused command to pcall without running it', function (): void {
    $canary = luaCanary();

    $run = luaRun(static fn (PhpRedis $r): mixed => $r->rawCommand('EVAL', "local r = redis.pcall('FLUSHDB'); return r['err']", '0'));

    expect($run['reply'])->toContain("no permissions to run the 'flushdb' command")
        ->and(Redis::connection()->get($canary))->toBe('alive');
});
