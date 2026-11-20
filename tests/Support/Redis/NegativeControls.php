<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use Tests\Support\ChildProcess;

/**
 * The unsafe Redis environments of ADR-0022 §7, and the child process that
 * proves each one stops before any command (tests/Probes/RedisProbeTest.php,
 * which writes a marker when it is allowed to run).
 *
 * The single source for tests/Redis/GuardFailsClosedTest.php and for the CI
 * step that runs the same children between two admin snapshots of
 * INFO commandstats (tests/Redis/bin/run-negative-controls.php), so the
 * server itself shows they sent nothing.
 */
final class NegativeControls
{
    /**
     * Case => [rule, environment overrides, another full key the marker could
     * land on, or null].
     *
     * @var array<string, array{0: string, 1: array<string, string>, 2: ?string}>
     */
    public const array CASES = [
        'R1 wrong host' => ['R1', ['REDIS_HOST' => '127.0.0.2'], null],
        'R1 remote host' => ['R1', ['REDIS_HOST' => 'redis.example.com'], null],
        'R2 URL' => ['R2', ['REDIS_URL' => 'redis://nexo_test:nexo_test@127.0.0.1:6379'], null],
        'R2 TLS URL' => ['R2', ['REDIS_URL' => 'tls://127.0.0.1:6379'], null],
        'R3 admin identity' => ['R3', ['REDIS_USERNAME' => 'nexo_ci_admin'], null],
        'R3 default user' => ['R3', ['REDIS_USERNAME' => ''], null],
        'R3 no password (Laravel would skip AUTH)' => ['R3', ['REDIS_PASSWORD' => ''], null],
        'R4 production prefix' => ['R4', ['REDIS_PREFIX' => 'nexo_production:'], null],
        'R4 other prefix inside the ACL namespace' => ['R4', ['REDIS_PREFIX' => 'nexo_testing:other:'], 'nexo_testing:other:probe:marker'],
        'R4 no prefix' => ['R4', ['REDIS_PREFIX' => ''], null],
        'R5 database 0 is still a SELECT' => ['R5', ['REDIS_DB' => '0'], null],
        'R5 cache database' => ['R5', ['REDIS_CACHE_DB' => '1'], null],
        'R6 wrong password' => ['R6', ['REDIS_PASSWORD' => 'not-the-password'], null],
    ];

    /**
     * The Redis connections RedisGate checks, in config/database.php.
     * tests/Feature/RedisManagerGuardTest.php fails if they change.
     *
     * @var list<string>
     */
    public const array CONNECTIONS = ['default', 'cache'];

    /**
     * The AUTH attempts the server should see. Only a wrong password reaches
     * it: the server alone can refuse that, and RedisGate tries each
     * configured connection, so that child sends one failed AUTH per
     * connection. Every other case is stopped by the pure guard before
     * connecting.
     */
    public static function authAttempts(): int
    {
        $children = count(array_filter(self::CASES, static fn (array $case): bool => $case[0] === 'R6'));

        return $children * count(self::CONNECTIONS);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array{exit: ?int, output: string}
     */
    public static function run(string $token, array $overrides): array
    {
        $process = ChildProcess::php(
            ['vendor/bin/pest', 'tests/Probes/RedisProbeTest.php', '--colors=never'],
            array_merge(['NEXO_REDIS_PROBE_TOKEN' => $token], $overrides),
        );
        $process->run();

        return ['exit' => $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
    }
}
