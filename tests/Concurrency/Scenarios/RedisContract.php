<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Limiters\DurationLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;

/**
 * Redis contract operations run by separate worker processes, each with its
 * own application and its own connections (ADR-0022 §7). Each worker boots
 * through Tests\CreatesApplication, so the test database guard and
 * GuardedRedisManager apply to it.
 *
 * Arguments: `op` selects the operation; the rest are documented per case.
 */
final class RedisContract implements Scenario
{
    public function prepare(Application $app, int $worker, array $arguments): void
    {
        // Connect (and prove the identity) before the barrier, so connection
        // time does not stagger the attempts.
        Redis::connection('default');
        Redis::connection('cache');
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        $key = (string) ($arguments['key'] ?? '');

        return match ($arguments['op'] ?? null) {
            // times: increments per worker.
            'increment' => ['values' => array_map(
                static fn (): int => (int) Cache::store('redis')->increment($key),
                range(1, (int) $arguments['times']),
            )],

            'add' => ['added' => Cache::store('redis')->add($key, 'worker-'.$worker, 60)],

            'put' => ['put' => Cache::store('redis')->put($key, (string) $arguments['value'], 60)],

            'lock' => self::lockRace($key),

            // Worker 0 holds the lock until worker 1 is blocking on it.
            'lock-handover' => $worker === 0 ? self::holdThenRelease($key, $context) : self::blockUntilReleased($key, $context),

            // Takes the lock and dies without releasing it.
            'lock-crash' => self::acquireAndDie($key, (int) $arguments['seconds']),

            // times: hits per worker on Laravel's RateLimiter (Redis store).
            'rate-hit' => ['hits' => array_map(
                static fn (): int => (new RateLimiter(Cache::store('redis')))->hit($key, 60),
                range(1, (int) $arguments['times']),
            )],

            // times: attempts per worker on Redis::throttle's DurationLimiter.
            'throttle' => ['admitted' => array_map(
                static fn (): bool => (new DurationLimiter(Redis::connection(), $key, (int) $arguments['max'], 60))->acquire(),
                range(1, (int) $arguments['times']),
            )],

            // A real worker for one job on one queue only.
            'queue-work' => self::work($app, (string) $arguments['queue']),

            default => throw new InvalidArgumentException('Unknown op: '.var_export($arguments['op'] ?? null, true)),
        };
    }

    private static function lock(string $key, int $seconds): Lock
    {
        $store = Cache::store('redis')->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The redis cache store provides no locks.');
        }

        return $store->lock($key, $seconds);
    }

    /**
     * @return array<string, mixed>
     */
    private static function lockRace(string $key): array
    {
        $lock = self::lock($key, 60);
        $acquired = $lock->get();

        return ['acquired' => $acquired, 'owner' => $lock->owner()];
    }

    /**
     * @return array<string, mixed>
     */
    private static function holdThenRelease(string $key, WorkerContext $context): array
    {
        $lock = self::lock($key, 60);
        $acquired = $lock->get();
        $context->signal('held');
        $context->waitFor('blocking');

        // Give worker 1 time to start polling; correctness does not depend
        // on it, only on the release happening before the acquire.
        usleep(300_000);
        $releasedAt = microtime(true);
        $released = $lock->release();

        return ['acquired' => $acquired, 'released' => $released, 'released_at' => $releasedAt];
    }

    /**
     * @return array<string, mixed>
     */
    private static function blockUntilReleased(string $key, WorkerContext $context): array
    {
        $context->waitFor('held');
        $lock = self::lock($key, 60);
        $context->signal('blocking');

        $acquired = $lock->block(10);
        $acquiredAt = microtime(true);
        $lock->release();

        return ['acquired' => $acquired, 'acquired_at' => $acquiredAt];
    }

    private static function acquireAndDie(string $key, int $seconds): never
    {
        $acquired = self::lock($key, $seconds)->get();

        if (! $acquired) {
            exit(6);
        }

        // An abrupt death: no release, no shutdown handlers.
        if (function_exists('posix_kill') && function_exists('posix_getpid')) {
            posix_kill(posix_getpid(), 9);
        }

        exit(9);
    }

    /**
     * @return array<string, mixed>
     */
    private static function work(Application $app, string $queue): array
    {
        // Failures are recorded by the job itself; no failed_jobs table. The
        // worker's pause and restart signals live in the default cache store,
        // so that is Redis too: this worker touches nothing but Redis.
        $app->make('config')->set('queue.failed.driver', 'null');
        $app->make('config')->set('cache.default', 'redis');

        $kernel = $app->make(Kernel::class);
        $exit = $kernel->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        return ['exit' => $exit, 'output' => $kernel->output()];
    }
}
