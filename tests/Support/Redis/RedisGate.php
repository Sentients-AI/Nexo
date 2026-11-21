<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Assert;
use Tests\Support\ContinuousIntegration;
use Tests\Support\Database\TestDatabaseGuardViolation;

/**
 * Entry check for every test in tests/Redis (ADR-0022 §5, §11).
 *
 * 1. Gate: the suite runs only sequentially, with NEXO_TEST_REDIS=1 and the
 *    redis extension. Locally a missing condition skips, with the reason; in
 *    CI it fails, so a mandatory proof can never turn into a skip.
 * 2. R6, live, once per process before the first test: for every configured
 *    Redis connection,
 *    - GuardedRedisManager connects (AUTH) and checks ACL WHOAMI;
 *    - NonExecutingProbe asks, on a dedicated connection with phpredis's
 *      reconnect switched off, inside a discarded MULTI, whether CONFIG GET,
 *      KEYS, FLUSHDB and FLUSHALL are permitted. Nothing queued is executed.
 *    The probe connections are then closed and never reused.
 *    Any failure is final for the process: every later test fails with the
 *    same reason and opens no connection. Connections after a failing one
 *    are still checked, so the report is complete.
 *
 * R1-R5 are pure and run earlier, in the test database guard.
 */
final class RedisGate
{
    private static ?string $failure = null;

    private static bool $verified = false;

    public static function enter(): void
    {
        // Every worker would share the fixed nexo_testing: prefix and the
        // probes' fixed marker keys, so Redis tests never run in parallel.
        if (self::parallelWorker()) {
            if (ContinuousIntegration::detected()) {
                Assert::fail('Redis tests cannot run under --parallel (TEST_TOKEN is set). CI must run them sequentially: php artisan test --testsuite=Redis.');
            }

            Assert::markTestSkipped('Redis tests share one key namespace; run them without --parallel (ADR-0022 §11).');
        }

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
     * Redis. False after a skip or a failure.
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

    private static function parallelWorker(): bool
    {
        return getenv('TEST_TOKEN') !== false || isset($_SERVER['TEST_TOKEN']);
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
            // A connection of its own, never cached or reused: resolve()
            // builds a fresh one (identity-checked by GuardedRedisManager),
            // and the probe closes it, so no test ever receives a client whose
            // retries were switched off or whose transaction state is unknown.
            try {
                $client = Redis::resolve($name)->client();
            } catch (TestDatabaseGuardViolation $e) {
                array_push($violations, ...$e->violations);

                continue;
            }

            if (! $client instanceof \Redis) {
                $violations[] = 'R6 redis connection ['.$name.'] is not a phpredis client';

                continue;
            }

            foreach (NonExecutingProbe::violationsOn($client) as $violation) {
                $violations[] = 'R6 redis connection ['.$name.'] '.$violation.'; the ADR-0022 ACL is not in force';
            }
        }

        return $violations;
    }
}
