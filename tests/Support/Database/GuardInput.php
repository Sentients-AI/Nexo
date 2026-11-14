<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * The facts the test database guard decides on.
 *
 * The named constructors only gather facts. All rules live in
 * TestDatabaseGuard::violations(), which is pure.
 */
final readonly class GuardInput
{
    /**
     * @param  array<string, array<string, mixed>>|null  $connections  Connection
     *                                                                 configurations by name, or null before the application has loaded
     *                                                                 its configuration (G3-G5 are then not evaluated).
     * @param  bool  $parallel  Running under Laravel's parallel runner.
     * @param  string|null  $parallelToken  This worker's TEST_TOKEN; null in the
     *                                      runner's parent process and when not parallel.
     */
    public function __construct(
        public bool $configCached,
        public ?string $configCachePath,
        public ?string $appEnv,
        public ?string $defaultConnection = null,
        public ?array $connections = null,
        public bool $parallel = false,
        public ?string $parallelToken = null,
    ) {}

    /**
     * Facts available before the application exists: G1 and G2.
     */
    public static function fromEnvironment(string $basePath): self
    {
        $path = self::configCachePath($basePath, self::env('APP_CONFIG_CACHE'));

        return new self(
            configCached: is_file($path) || is_file($basePath.'/bootstrap/cache/config.php'),
            configCachePath: $path,
            appEnv: self::env('APP_ENV'),
            parallel: self::parallel(),
            parallelToken: self::parallelToken(),
        );
    }

    /**
     * Facts from a bootstrapped (or configuration-loaded) application: G1-G5.
     */
    public static function fromApplication(Application $app): self
    {
        /** @var Repository $config */
        $config = $app->make('config');

        $path = $app->getCachedConfigPath();
        $default = $config->get('database.default');
        $default = is_string($default) ? $default : null;

        /** @var array<string, array<string, mixed>> $all */
        $all = (array) $config->get('database.connections', []);

        return new self(
            configCached: $app->configurationIsCached()
                || is_file($path)
                || is_file($app->bootstrapPath('cache/config.php')),
            configCachePath: $path,
            appEnv: is_string($env = $app['env'] ?? null) ? $env : null,
            defaultConnection: $default,
            connections: self::connectionsUsedByTests($config, $default, $all),
            parallel: self::parallel(),
            parallelToken: self::parallelToken(),
        );
    }

    public function withAppEnv(?string $appEnv): self
    {
        return new self(
            $this->configCached,
            $this->configCachePath,
            $appEnv,
            $this->defaultConnection,
            $this->connections,
            $this->parallel,
            $this->parallelToken,
        );
    }

    /**
     * Every connection tests can reach: the default, every MySQL or MariaDB
     * connection, and every connection the cache, queue and session
     * configuration names. A named connection that is not configured is
     * included as an empty configuration, so it fails closed.
     *
     * @param  array<string, array<string, mixed>>  $all
     * @return array<string, array<string, mixed>>
     */
    private static function connectionsUsedByTests(
        Repository $config,
        ?string $default,
        array $all,
    ): array {
        $names = [];

        if ($default !== null) {
            $names[] = $default;
        }

        foreach ($all as $name => $connection) {
            if (in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
                $names[] = $name;
            }
        }

        foreach ([
            'cache.stores.database.connection',
            'cache.stores.database.lock_connection',
            'queue.connections.database.connection',
            'session.connection',
        ] as $key) {
            $name = $config->get($key);

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        $connections = [];

        foreach (array_unique($names) as $name) {
            $connections[$name] = $all[$name] ?? [];
        }

        return $connections;
    }

    private static function configCachePath(string $basePath, ?string $env): string
    {
        if ($env === null || $env === '') {
            return $basePath.'/bootstrap/cache/config.php';
        }

        $absolute = str_starts_with($env, '/')
            || str_starts_with($env, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $env) === 1;

        return $absolute ? $env : $basePath.'/'.$env;
    }

    private static function parallel(): bool
    {
        $value = self::env('LARAVEL_PARALLEL_TESTING');

        return $value !== null && $value !== '' && $value !== '0';
    }

    private static function parallelToken(): ?string
    {
        if (! self::parallel()) {
            return null;
        }

        $token = self::env('TEST_TOKEN');

        return $token === null || $token === '' ? null : $token;
    }

    private static function env(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
