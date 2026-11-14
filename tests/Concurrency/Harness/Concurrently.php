<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Process\Process;
use Tests\Support\ChildProcess;

/**
 * Runs a Scenario in N separate PHP processes released together by a start
 * barrier (ADR-0009 §3).
 *
 * - Each worker boots its own application through Tests\CreatesApplication,
 *   so it enforces the test database guard itself.
 * - Workers receive the parent's runtime database name explicitly (which
 *   already carries any --parallel suffix) and never read a developer .env
 *   for it.
 * - The barrier: each worker writes ready-<i> after prepare(), then waits for
 *   `go`. The parent writes `go` only when every worker is ready.
 * - The whole run, from spawning to the last result, must finish within the
 *   timeout (default 30 s). Otherwise every worker is killed and
 *   ConcurrencyTimeout is thrown.
 * - Fixtures must be committed before the run: use DatabaseTruncation, not
 *   RefreshDatabase, whose open transaction workers cannot see.
 */
final class Concurrently
{
    public const float DEFAULT_TIMEOUT = 30.0;

    public const string RESULT_MARKER = 'NEXO-WORKER-RESULT ';

    private const int POLL_MICROSECONDS = 1000;

    /**
     * @param  class-string<Scenario>  $scenario
     * @param  array<string, mixed>  $arguments  Passed to every worker.
     * @param  array<string, string|false>  $environment  Extra variables for every worker.
     * @param  array<int, array<string, string|false>>  $workerEnvironment  Extra variables per worker index.
     * @return list<WorkerResult> Ordered by worker index.
     */
    public static function run(
        Application $app,
        string $scenario,
        int $workers,
        array $arguments = [],
        float $timeout = self::DEFAULT_TIMEOUT,
        array $environment = [],
        array $workerEnvironment = [],
    ): array {
        if ($workers < 1) {
            throw new InvalidArgumentException('At least one worker is required.');
        }

        if (! is_subclass_of($scenario, Scenario::class)) {
            throw new InvalidArgumentException("{$scenario} does not implement ".Scenario::class.'.');
        }

        $runDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nexo-concurrency-'.bin2hex(random_bytes(8));

        if (! mkdir($runDirectory, 0700) && ! is_dir($runDirectory)) {
            throw new \RuntimeException("Could not create {$runDirectory}.");
        }

        $deadline = microtime(true) + $timeout;
        $processes = [];

        try {
            $base = array_merge(self::databaseEnvironment($app), $environment);
            $encodedArguments = base64_encode(json_encode($arguments, JSON_THROW_ON_ERROR));

            for ($worker = 0; $worker < $workers; $worker++) {
                $process = ChildProcess::php(
                    ['tests/Concurrency/bin/worker.php', $scenario, $runDirectory, (string) $worker, $encodedArguments],
                    array_merge($base, $workerEnvironment[$worker] ?? []),
                    $timeout + 5,
                );
                $process->start();
                $processes[$worker] = $process;
            }

            // Barrier: wait until every worker is ready, failing fast if one exits.
            while (count(glob($runDirectory.DIRECTORY_SEPARATOR.'ready-*') ?: []) < $workers) {
                foreach ($processes as $worker => $process) {
                    if (! $process->isRunning()) {
                        self::stopAll($processes);

                        throw self::failure($worker, $process);
                    }
                }

                self::checkDeadline($deadline, $timeout, $processes);
                usleep(self::POLL_MICROSECONDS);
            }

            file_put_contents($runDirectory.DIRECTORY_SEPARATOR.'go', (string) microtime(true));

            while (array_filter($processes, static fn (Process $p): bool => $p->isRunning()) !== []) {
                self::checkDeadline($deadline, $timeout, $processes);
                usleep(self::POLL_MICROSECONDS);
            }

            $results = [];

            foreach ($processes as $worker => $process) {
                $results[] = self::result($worker, $process);
            }

            return $results;
        } finally {
            self::stopAll($processes);
            self::removeDirectory($runDirectory);
        }
    }

    /**
     * The runtime database settings of the parent's default connection, plus
     * the cache store and lock connection the tests use.
     *
     * @return array<string, string|false>
     */
    private static function databaseEnvironment(Application $app): array
    {
        $config = $app->make('config');
        $default = $config->get('database.default');
        /** @var array<string, mixed> $connection */
        $connection = (array) $config->get("database.connections.{$default}", []);

        $string = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $string($default),
            'DB_HOST' => $string($connection['host'] ?? ''),
            'DB_PORT' => $string($connection['port'] ?? ''),
            'DB_DATABASE' => $string($connection['database'] ?? ''),
            'DB_USERNAME' => $string($connection['username'] ?? ''),
            'DB_PASSWORD' => $string($connection['password'] ?? ''),
            'DB_URL' => '',
            'DB_SOCKET' => '',
            'CACHE_STORE' => 'database',
            'DB_CACHE_LOCK_CONNECTION' => 'mysql_locks',
        ];
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private static function checkDeadline(float $deadline, float $timeout, array $processes): void
    {
        if (microtime(true) <= $deadline) {
            return;
        }

        self::stopAll($processes);

        throw new ConcurrencyTimeout($timeout, array_values($processes));
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private static function stopAll(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    private static function result(int $worker, Process $process): WorkerResult
    {
        $report = self::report($process);

        if ($process->getExitCode() !== 0 || ($report['status'] ?? null) !== 'ok') {
            throw self::failure($worker, $process, $report);
        }

        /** @var array<string, mixed> $data */
        $data = is_array($report['data'] ?? null) ? $report['data'] : [];

        return new WorkerResult(
            worker: $worker,
            readyAt: (float) $report['ready_at'],
            releasedAt: (float) $report['released_at'],
            finishedAt: (float) $report['finished_at'],
            data: $data,
        );
    }

    /**
     * @param  array<string, mixed>|null  $report
     */
    private static function failure(int $worker, Process $process, ?array $report = null): WorkerFailed
    {
        $report ??= self::report($process);
        $status = is_string($report['status'] ?? null) ? $report['status'] : 'no_result';

        $details = is_string($report['message'] ?? null)
            ? ($report['class'] ?? '').' '.$report['message']
            : trim($process->getOutput()."\n".$process->getErrorOutput());

        return new WorkerFailed($worker, $status, 'exit code '.var_export($process->getExitCode(), true)."\n".$details);
    }

    /**
     * @return array<string, mixed>
     */
    private static function report(Process $process): array
    {
        foreach (array_reverse(explode("\n", $process->getOutput())) as $line) {
            if (str_starts_with($line, self::RESULT_MARKER)) {
                try {
                    $decoded = json_decode(substr($line, strlen(self::RESULT_MARKER)), true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return [];
                }

                return is_array($decoded) ? $decoded : [];
            }
        }

        return [];
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
