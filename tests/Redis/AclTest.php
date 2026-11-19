<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Redis\RedisGate;

/*
 * ADR-0022 §4, §6: the server refuses every prohibited command to the test
 * identity. Each command is sent with valid arity and, where it takes keys,
 * keys inside nexo_testing:, so the refusal is the command rule, not the key
 * pattern. RedisGate (R6) has already proven a restrictive ACL is in force,
 * so none of these can run.
 *
 * phpredis throws on NOPERM but returns false for other error replies, so the
 * refusal is read from either.
 */

/**
 * @param  list<string>  $command
 */
function redisRefusal(array $command): string
{
    $client = Redis::connection()->client();
    $client->clearLastError();

    try {
        $reply = $client->rawCommand(...$command);
    } catch (RedisException $e) {
        return $e->getMessage();
    }

    return $reply === false ? (string) $client->getLastError() : 'ALLOWED: '.var_export($reply, true);
}

it('authenticates every configured connection as nexo_test', function (): void {
    foreach (RedisGate::connectionNames() as $name) {
        expect(Redis::connection($name)->client()->rawCommand('ACL', 'WHOAMI'))->toBe('nexo_test');
    }
});

it('refuses a prohibited command', function (string $rule, array $command): void {
    expect(redisRefusal($command))
        ->toStartWith("NOPERM User nexo_test has no permissions to run the '{$rule}' command");
})->with([
    'FLUSHALL' => ['flushall', ['FLUSHALL']],
    'FLUSHDB' => ['flushdb', ['FLUSHDB']],
    'SWAPDB' => ['swapdb', ['SWAPDB', '0', '1']],
    'SELECT' => ['select', ['SELECT', '1']],
    'MOVE' => ['move', ['MOVE', 'nexo_testing:acl:a', '1']],
    'MIGRATE' => ['migrate', ['MIGRATE', '127.0.0.1', '6380', 'nexo_testing:acl:a', '0', '1']],
    'RENAME' => ['rename', ['RENAME', 'nexo_testing:acl:a', 'nexo_testing:acl:b']],
    'RENAMENX' => ['renamenx', ['RENAMENX', 'nexo_testing:acl:a', 'nexo_testing:acl:b']],
    'KEYS' => ['keys', ['KEYS', 'nexo_testing:*']],
    'SCAN' => ['scan', ['SCAN', '0']],
    'RANDOMKEY' => ['randomkey', ['RANDOMKEY']],
    'CONFIG GET' => ['config|get', ['CONFIG', 'GET', 'maxmemory']],
    'CONFIG SET' => ['config|set', ['CONFIG', 'SET', 'loglevel', 'notice']],
    'CONFIG RESETSTAT' => ['config|resetstat', ['CONFIG', 'RESETSTAT']],
    'SHUTDOWN' => ['shutdown', ['SHUTDOWN', 'NOSAVE']],
    'SAVE' => ['save', ['SAVE']],
    'BGSAVE' => ['bgsave', ['BGSAVE']],
    'BGREWRITEAOF' => ['bgrewriteaof', ['BGREWRITEAOF']],
    'REPLICAOF' => ['replicaof', ['REPLICAOF', 'NO', 'ONE']],
    'SLAVEOF' => ['slaveof', ['SLAVEOF', 'NO', 'ONE']],
    'FAILOVER' => ['failover', ['FAILOVER', 'ABORT']],
    'CLUSTER' => ['cluster|info', ['CLUSTER', 'INFO']],
    'MODULE' => ['module|list', ['MODULE', 'LIST']],
    'ACL LIST' => ['acl|list', ['ACL', 'LIST']],
    'ACL GETUSER' => ['acl|getuser', ['ACL', 'GETUSER', 'nexo_test']],
    'ACL SETUSER' => ['acl|setuser', ['ACL', 'SETUSER', 'nexo_test', '+@all']],
    'ACL DELUSER' => ['acl|deluser', ['ACL', 'DELUSER', 'nexo_ci_admin']],
    'ACL LOAD' => ['acl|load', ['ACL', 'LOAD']],
    'ACL SAVE' => ['acl|save', ['ACL', 'SAVE']],
    'MONITOR' => ['monitor', ['MONITOR']],
    'CLIENT KILL' => ['client|kill', ['CLIENT', 'KILL', 'ID', '999999999']],
    'CLIENT PAUSE' => ['client|pause', ['CLIENT', 'PAUSE', '1']],
    'SCRIPT FLUSH' => ['script|flush', ['SCRIPT', 'FLUSH']],
    'FUNCTION FLUSH' => ['function|flush', ['FUNCTION', 'FLUSH']],
    'FUNCTION DELETE' => ['function|delete', ['FUNCTION', 'DELETE', 'nexo_testing']],
    'FUNCTION LOAD' => ['function|load', ['FUNCTION', 'LOAD', "#!lua name=nexo_testing\nserver.register_function('f', function() return 1 end)"]],
]);

// Valkey's default enable-debug-command=no refuses DEBUG before the ACL is
// consulted, so this layer is the one a client can observe. The ACL denies it
// as well (-@all; tests/Unit/UsersAclTest.php).
it('refuses DEBUG', function (): void {
    expect(redisRefusal(['DEBUG', 'SLEEP', '0']))->toStartWith('ERR DEBUG command not allowed');
});

it('makes the Redis cache store\'s flush unusable', function (): void {
    expect(fn () => Cache::store('redis')->flush())
        ->toThrow(RedisException::class, "NOPERM User nexo_test has no permissions to run the 'flushdb' command");
});
