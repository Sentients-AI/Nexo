<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Builds child PHP processes for the test platform proofs.
 *
 * The child inherits the parent's environment (so CI credentials pass
 * through) minus anything that would make it think it is a parallel worker,
 * plus the given overrides. A `false` override removes the variable.
 */
final class ChildProcess
{
    private const array PARALLEL_VARIABLES = [
        'TEST_TOKEN',
        'UNIQUE_TEST_TOKEN',
        'LARAVEL_PARALLEL_TESTING',
        'LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES',
        'LARAVEL_PARALLEL_TESTING_DROP_DATABASES',
        'LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES',
        'LARAVEL_PARALLEL_TESTING_WITHOUT_CACHE',
        'PARATEST',
    ];

    /**
     * @param  list<string>  $arguments  Arguments after the PHP binary.
     * @param  array<string, string|false>  $overrides
     */
    public static function php(array $arguments, array $overrides = [], float $timeout = 120): Process
    {
        $env = array_fill_keys(self::PARALLEL_VARIABLES, false);

        // Always start from the test database settings the parent was given,
        // never from a developer's .env.
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DB_SOCKET'] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            $env[$key] = is_string($value) ? $value : false;
        }

        $env['APP_CONFIG_CACHE'] = false;

        $process = new Process(
            [PHP_BINARY, ...$arguments],
            dirname(__DIR__, 2),
            array_merge($env, $overrides),
            null,
            $timeout,
        );

        return $process;
    }

    /**
     * Run the migrating probe in a child Pest process.
     *
     * @param  array<string, string|false>  $overrides
     * @param  list<string>  $pestArguments
     */
    public static function probe(array $overrides, array $pestArguments = []): Process
    {
        $process = self::php(['vendor/bin/pest', 'tests/Probes/MigratingProbeTest.php', '--colors=never', ...$pestArguments], $overrides);
        $process->run();

        return $process;
    }
}
