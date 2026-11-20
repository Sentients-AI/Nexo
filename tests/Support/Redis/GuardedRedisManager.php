<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use RedisException;
use Tests\Support\Database\TestDatabaseGuard;
use Tests\Support\Database\TestDatabaseGuardViolation;

/**
 * The Redis manager every test application uses (Tests\CreatesApplication).
 *
 * It checks the identity of each connection as it is resolved, before any
 * caller can send a command (ADR-0022 §5, R6 at connection time). A
 * configured username does not prove that AUTH happened: Laravel skips AUTH
 * when the password is empty, and the connection then runs as the server's
 * `default` user. So the server is asked, with ACL WHOAMI, the only command
 * sent before the connection is handed out.
 *
 * This covers every test, including Unit and Feature tests outside
 * tests/Redis that never pass through RedisGate.
 */
final class GuardedRedisManager extends RedisManager
{
    public static function install(Application $app): void
    {
        // Load the deferred RedisServiceProvider first, so it cannot
        // register its own manager over this one later.
        $app->make('redis');

        /** @var array<string, mixed> $config */
        $config = (array) $app->make('config')->get('database.redis', []);
        $driver = Arr::pull($config, 'client', 'phpredis');

        $app->instance('redis', new self($app, is_string($driver) ? $driver : 'phpredis', $config));
    }

    /**
     * @param  string|null  $name
     * @return Connection
     */
    public function resolve($name = null)
    {
        $name = $name ?: 'default';

        try {
            $connection = parent::resolve($name);
            $client = $connection->client();
            $whoami = $client instanceof \Redis ? $client->rawCommand('ACL', 'WHOAMI') : get_debug_type($client);
        } catch (RedisException $e) {
            throw new TestDatabaseGuardViolation(['R6 redis connection ['.$name.'] cannot authenticate as '
                .TestDatabaseGuard::REDIS_USERNAME.': '.$e->getMessage()]);
        }

        $violation = self::identityViolation($name, $whoami);

        if ($violation !== null) {
            if ($client instanceof \Redis) {
                $client->close();
            }

            throw new TestDatabaseGuardViolation([$violation]);
        }

        return $connection;
    }

    public static function identityViolation(string $name, mixed $whoami): ?string
    {
        if ($whoami === TestDatabaseGuard::REDIS_USERNAME) {
            return null;
        }

        return 'R6 redis connection ['.$name.'] is authenticated as '.var_export($whoami, true)
            .", expected '".TestDatabaseGuard::REDIS_USERNAME."'";
    }
}
