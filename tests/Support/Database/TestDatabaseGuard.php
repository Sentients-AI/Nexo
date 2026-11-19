<?php

declare(strict_types=1);

namespace Tests\Support\Database;

/**
 * The single, authoritative implementation of the test database guard
 * (ADR-0009 §3a), with its Redis rules R1-R5 (ADR-0022 §5). It is pure: the same input always gives the same
 * violations, and it performs no I/O.
 *
 * It is enforced in tests/bootstrap.php, in Tests\CreatesApplication (every
 * test case, the parallel runner's per-process set-up and every concurrency
 * worker) and after the parallel database switch.
 */
final class TestDatabaseGuard
{
    public const string BASE_DATABASE = 'nexo_testing';

    /** `D`: `$` must not match before a trailing newline. */
    public const string DATABASE_PATTERN = '/^nexo_testing(_test_[1-9][0-9]*)?$/D';

    /** @var list<string> */
    public const array ALLOWED_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    public const string REDIS_USERNAME = 'nexo_test';

    /** `D`: `$` must not match before a trailing newline. */
    public const string REDIS_PREFIX_PATTERN = '/^nexo_testing:(test_[1-9][0-9]*:)?$/D';

    /**
     * @return list<string> Each violation starts with its rule id (G1-G5, R1-R5).
     */
    public static function violations(GuardInput $input): array
    {
        $violations = [];

        if ($input->redis !== null) {
            array_push($violations, ...self::redisViolations($input->redis, $input->redisClustersConfigured));
        }

        if ($input->configCached) {
            $violations[] = 'G1 configuration is cached ('.($input->configCachePath ?? 'bootstrap/cache/config.php')
                .'); delete it, tests must read phpunit.xml';
        }

        if ($input->appEnv !== 'testing') {
            $violations[] = 'G2 APP_ENV is '.self::show($input->appEnv).", expected 'testing'";
        }

        if ($input->connections === null) {
            return $violations;
        }

        $default = $input->defaultConnection;

        if ($default === null || ! array_key_exists($default, $input->connections)) {
            $violations[] = 'G5 the default connection '.self::show($default).' is not configured';
        } elseif (($input->connections[$default]['driver'] ?? null) !== 'mysql') {
            $violations[] = 'G5 the default connection ['.$default.'] uses driver '
                .self::show($input->connections[$default]['driver'] ?? null).", expected 'mysql'";
        }

        foreach ($input->connections as $name => $connection) {
            foreach (self::effectiveConfigurations($connection) as $part => $config) {
                $label = 'connection ['.$name.($part === '' ? '' : '.'.$part).']';

                array_push($violations, ...self::connectionViolations($label, $config));

                if ($input->parallel) {
                    array_push($violations, ...self::parallelViolations($label, $config, $input->parallelToken));
                }
            }
        }

        return $violations;
    }

    public static function enforce(GuardInput $input): void
    {
        $violations = self::violations($input);

        if ($violations !== []) {
            throw new TestDatabaseGuardViolation($violations);
        }
    }

    /**
     * The base configuration, and each read/write sub-configuration merged
     * over it, the way Laravel's ConnectionFactory merges them.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, array<string, mixed>>
     */
    private static function effectiveConfigurations(array $connection): array
    {
        $base = $connection;
        unset($base['read'], $base['write']);

        $configs = ['' => $base];

        foreach (['read', 'write'] as $part) {
            if (isset($connection[$part]) && is_array($connection[$part])) {
                /** @var array<string, mixed> $sub */
                $sub = $connection[$part];
                $configs[$part] = array_merge($base, $sub);
            }
        }

        return $configs;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function connectionViolations(string $label, array $config): array
    {
        $violations = [];

        $hosts = $config['host'] ?? null;
        $hosts = is_array($hosts) ? array_values($hosts) : [$hosts];

        if ($hosts === []) {
            $hosts = [null];
        }

        foreach ($hosts as $host) {
            if (! is_string($host) || ! in_array($host, self::ALLOWED_HOSTS, true)) {
                $violations[] = 'G3 '.$label.' host '.self::show($host)
                    .' is not allowlisted ('.implode(', ', self::ALLOWED_HOSTS).')';
            }
        }

        foreach (['url', 'unix_socket'] as $key) {
            $value = $config[$key] ?? null;

            if ($value !== null && $value !== '') {
                $violations[] = 'G4 '.$label.' '.$key.' must be empty, got '.self::show($value);
            }
        }

        $database = $config['database'] ?? null;

        if (! is_string($database) || preg_match(self::DATABASE_PATTERN, $database) !== 1) {
            $violations[] = 'G5 '.$label.' database '.self::show($database)
                .' does not match '.self::DATABASE_PATTERN;
        }

        return $violations;
    }

    /**
     * Under --parallel, Laravel creates (and with --recreate-databases or
     * --drop-databases, drops) `<database>_test_<N>` before any later check
     * can run. So the database must be exactly `nexo_testing`, which makes
     * every derived name `nexo_testing_test_<N>`, or, after the switch, this
     * worker's own `nexo_testing_test_<token>`.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function parallelViolations(string $label, array $config, ?string $token): array
    {
        $database = $config['database'] ?? null;
        $allowed = [self::BASE_DATABASE];

        if ($token !== null) {
            $allowed[] = self::BASE_DATABASE.'_test_'.$token;
        }

        if (in_array($database, $allowed, true)) {
            return [];
        }

        return ['G5 '.$label.' database '.self::show($database).' under --parallel must be '
            .implode(' or ', array_map(self::show(...), $allowed))];
    }

    /**
     * ADR-0022 §5, R1-R5. R6 is the live check (Tests\Support\Redis\RedisGate).
     *
     * @param  array<string, array<string, mixed>>  $connections
     * @return list<string>
     */
    private static function redisViolations(array $connections, bool $clustersConfigured): array
    {
        $violations = [];

        if ($clustersConfigured) {
            $violations[] = 'R2 redis clusters are configured; the tests use one standalone server';
        }

        foreach ($connections as $name => $config) {
            $label = 'redis connection ['.$name.']';
            $host = $config['host'] ?? null;

            if (! is_string($host) || ! in_array($host, self::ALLOWED_HOSTS, true)) {
                $violations[] = 'R1 '.$label.' host '.self::show($host)
                    .' is not allowlisted ('.implode(', ', self::ALLOWED_HOSTS).')';
            }

            // No URL (it would override host and credentials), no TLS scheme or
            // stream context, no cluster.
            foreach (['url', 'scheme', 'context', 'cluster_name'] as $key) {
                $value = $config[$key] ?? null;

                if ($value !== null && $value !== '' && $value !== []) {
                    $violations[] = 'R2 '.$label.' '.$key.' must be empty, got '.self::show($value);
                }
            }

            $username = $config['username'] ?? null;

            if ($username !== self::REDIS_USERNAME) {
                $violations[] = 'R3 '.$label.' username '.self::show($username)
                    .", expected '".self::REDIS_USERNAME."'";
            }

            $prefix = $config['prefix'] ?? null;

            if (! is_string($prefix) || preg_match(self::REDIS_PREFIX_PATTERN, $prefix) !== 1) {
                $violations[] = 'R4 '.$label.' prefix '.self::show($prefix)
                    .' does not match '.self::REDIS_PREFIX_PATTERN;
            }

            // Laravel sends SELECT whenever a database is set, even 0, and the
            // ACL denies SELECT. Unset keeps every connection on database 0.
            $database = $config['database'] ?? null;

            if ($database !== null) {
                $violations[] = 'R5 '.$label.' database must be unset (database 0, no SELECT), got '.self::show($database);
            }
        }

        return $violations;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_string($value) => "'".addcslashes($value, "\0..\37")."'",
            is_scalar($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }
}
