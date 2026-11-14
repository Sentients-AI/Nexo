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
 * Two "parallel test processes" on different databases. Worker 0 holds a
 * lock; worker 1 runs the per-test lock cleanup on its own database; worker
 * 0 then checks that its lock survived. The order is fixed by signals, not
 * by sleeps.
 */
final class CleanLocksWhileAnotherHolds implements Scenario
{
    public function prepare(Application $app, int $worker, array $arguments): void
    {
        LockConnection::serverDatabase(LockConnection::storeConnection($app));
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        $connection = LockConnection::storeConnection($app);
        $database = LockConnection::serverDatabase($connection);

        if ($worker === 1) {
            $context->waitFor('held');
            $deleted = LockConnection::clean($app);
            $context->signal('cleaned');

            return ['database' => $database, 'deleted' => $deleted];
        }

        $lock = self::lockProvider($app, 'database')->lock('held-across-processes', 60);
        $acquired = $lock->get();
        $context->signal('held');

        $context->waitFor('cleaned');
        $survived = $connection->table(LockConnection::table($app))->where('key', 'like', '%held-across-processes')->count();
        $lock->release();

        return ['database' => $database, 'acquired' => $acquired, 'rows_after_other_cleaned' => $survived];
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
