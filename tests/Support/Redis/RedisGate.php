<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Assert;
use RedisException;
use Tests\Support\ContinuousIntegration;
use Tests\Support\Database\TestDatabaseGuard;
use Tests\Support\Database\TestDatabaseGuardViolation;

/**
 * Entry check for every test that talks to Redis (ADR-0022 §5, §11).
 *
 * 1. Gate: the suite runs only with NEXO_TEST_REDIS=1 and the redis
 *    extension. Locally a missing condition skips, with the reason; in CI it
 *    fails, so a mandatory proof can never turn into a skip.
 * 2. R6, live, once per process before the first test command: every
 *    configured Redis connection authenticates as nexo_test and the server
 *    refuses destructive commands. Harmless denied commands are probed
 *    before FLUSHDB, so FLUSHDB is only ever sent to a server that has
 *    already proven it enforces a restrictive ACL. Any failure is final for
 *    the process: every later test fails with the same reason, and nothing
 *    else is sent.
 *
 * R1-R5 are pure and run earlier, in the test database guard.
 */
final class RedisGate
{
    private static ?string $failure = null;

    private static bool $verified = false;

    public static function enter(): void
    {
        $missing = self::missingPrerequisite();

        if ($missing !== null) {
            if (ContinuousIntegration::detected()) {
                Assert::fail('ADR-0022 requires the Redis suite in CI, but '.$missing.'.');
            }

            Assert::markTestSkipped('Redis suite not run: '.$missing.' (ADR-0022 §11).');
        }

        if (self::$failure === null && ! self::$verified) {
            $violations = self::liveViolations();
            self::$verified = $violations === [];
            self::$failure = $violations === [] ? null : (new TestDatabaseGuardViolation($violations))->getMessage();
        }

        if (self::$failure !== null) {
            Assert::fail(self::$failure);
        }
    }

    /**
     * Whether this process passed the gate and R6, so clean-up may talk to
     * Redis. False after a skip.
     */
    public static function passed(): bool
    {
        return self::$verified;
    }

    /**
     * Names of every configured Redis connection, as the guard checks them.
     *
     * @return list<string>
     */
    public static function connectionNames(): array
    {
        /** @var array<string, mixed> $redis */
        $redis = (array) Config::get('database.redis', []);

        return array_values(array_map(
            strval(...),
            array_diff(array_keys($redis), ['client', 'options', 'clusters']),
        ));
    }

    private static function missingPrerequisite(): ?string
    {
        if (getenv('NEXO_TEST_REDIS') !== '1') {
            return 'NEXO_TEST_REDIS is not 1';
        }

        if (! extension_loaded('redis')) {
            return 'the redis (phpredis) extension is not loaded';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function liveViolations(): array
    {
        $violations = [];

        foreach (self::connectionNames() as $name) {
            $label = 'R6 redis connection ['.$name.']';

            try {
                $client = Redis::connection($name)->client();
                $whoami = $client->rawCommand('ACL', 'WHOAMI');
            } catch (RedisException $e) {
                $violations[] = $label.' cannot authenticate as '.TestDatabaseGuard::REDIS_USERNAME.': '.$e->getMessage();

                continue;
            }

            if ($whoami !== TestDatabaseGuard::REDIS_USERNAME) {
                $violations[] = $label.' is authenticated as '.var_export($whoami, true)
                    .", expected '".TestDatabaseGuard::REDIS_USERNAME."'";

                continue;
            }

            foreach ([['CONFIG', 'GET', 'maxmemory'], ['KEYS', 'nexo_testing:r6-probe'], ['FLUSHDB']] as $command) {
                if (! self::refused($client, $command)) {
                    $violations[] = $label.' was allowed to run '.implode(' ', $command)
                        .'; the ADR-0022 ACL is not in force';

                    continue 2;
                }
            }
        }

        return $violations;
    }

    /**
     * @param  list<string>  $command
     */
    private static function refused(\Redis $client, array $command): bool
    {
        $client->clearLastError();

        try {
            $reply = $client->rawCommand(...$command);
        } catch (RedisException $e) {
            return str_starts_with($e->getMessage(), 'NOPERM');
        }

        // phpredis returns false, not an exception, for some error replies.
        return $reply === false && str_starts_with((string) $client->getLastError(), 'NOPERM');
    }
}
