<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;
use Tests\Support\Database\LockConnection;

/**
 * Every worker tries to take the same `database` store lock at once. The
 * winner holds it for `hold_ms`, then releases it.
 *
 * Arguments: key (string), hold_ms (int), store (optional, default
 * "database"; "array" is a per-process store, used as a negative control).
 */
final class AcquireDatabaseLock implements Scenario
{
    public function prepare(Application $app, int $worker, array $arguments): void
    {
        // Connect before the barrier so connection time does not stagger the
        // attempts.
        if (self::store($arguments) === 'database') {
            LockConnection::serverDatabase(LockConnection::storeConnection($app));
        }
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        $store = self::store($arguments);
        $lock = self::lockProvider($app, $store)->lock((string) $arguments['key'], 30);

        $attemptedAt = microtime(true);
        $acquired = $lock->get();
        $attemptFinishedAt = microtime(true);

        $releasedAt = null;

        if ($acquired) {
            usleep((int) $arguments['hold_ms'] * 1000);
            $releasedAt = microtime(true);
            $lock->release();
        }

        $connection = $store === 'database' ? LockConnection::storeConnection($app) : null;

        return [
            'acquired' => $acquired,
            'attempted_at' => $attemptedAt,
            'attempt_finished_at' => $attemptFinishedAt,
            'released_at' => $releasedAt,
            'lock_connection' => $connection?->getName(),
            'lock_database' => $connection === null ? null : LockConnection::serverDatabase($connection),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function store(array $arguments): string
    {
        return is_string($arguments['store'] ?? null) ? $arguments['store'] : 'database';
    }

    private static function lockProvider(Application $app, string $store): LockProvider
    {
        $provider = $app->make('cache')->store($store)->getStore();

        if (! $provider instanceof LockProvider) {
            throw new RuntimeException("Cache store '{$store}' does not support locks.");
        }

        return $provider;
    }
}
