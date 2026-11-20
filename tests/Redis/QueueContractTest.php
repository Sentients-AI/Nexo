<?php

declare(strict_types=1);

use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Redis;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Scenarios\RedisContract;
use Tests\Support\Redis\Eventually;
use Tests\Support\Redis\RecordingJob;
use Tests\Support\Redis\RedisKeys;

/*
 * ADR-0022 §7: Laravel's Redis queue on the restricted identity. Each test
 * uses its own queue name, so no other job is ever seen, and the only worker
 * is a harness process running `queue:work --once` on that queue.
 */

/**
 * @return array{0: string, 1: string} The queue name and its full list key.
 */
function contractQueue(): array
{
    $queue = 'contract-'.bin2hex(random_bytes(6));

    foreach (['', ':delayed', ':reserved', ':notify'] as $suffix) {
        RedisKeys::track('queues:'.$queue.$suffix);
    }

    return [$queue, 'nexo_testing:queues:'.$queue];
}

/**
 * Laravel's Redis queue on the `default` connection, non-blocking, with the
 * given reservation timeout.
 */
function redisQueue(int $retryAfter = 90): RedisQueue
{
    $queue = new RedisQueue(app('redis'), 'default', 'default', $retryAfter, null);
    $queue->setContainer(app());
    $queue->setConnectionName('redis');

    return $queue;
}

function zcard(string $fullKey): int
{
    return (int) Redis::connection()->client()->rawCommand('ZCARD', $fullKey);
}

function trackJob(string $token): void
{
    RedisKeys::track(RecordingJob::handledKey($token));
    RedisKeys::track(RecordingJob::failedKey($token));
}

it('enqueues a job into the prefixed list', function (): void {
    [$queue, $full] = contractQueue();

    redisQueue()->push(new RecordingJob('enqueue'), '', $queue);

    expect((int) Redis::connection()->client()->rawCommand('LLEN', $full))->toBe(1)
        ->and(redisQueue()->size($queue))->toBe(1);
});

it('reserves a job, then acknowledges it by deleting it', function (): void {
    [$queue, $full] = contractQueue();
    $connection = redisQueue();
    $connection->push(new RecordingJob('reserve'), '', $queue);

    $job = $connection->pop($queue);

    expect($job)->toBeInstanceOf(RedisJob::class)
        ->and($job->attempts())->toBe(1)
        ->and(zcard($full.':reserved'))->toBe(1)
        ->and($connection->pop($queue))->toBeNull();

    $job->delete();

    expect(zcard($full.':reserved'))->toBe(0)
        ->and($connection->size($queue))->toBe(0);
});

it('releases a job for retry with its attempt count', function (): void {
    [$queue, $full] = contractQueue();
    $connection = redisQueue();
    $connection->push(new RecordingJob('release'), '', $queue);

    $connection->pop($queue)->release(0);

    expect(zcard($full.':reserved'))->toBe(0);

    $retried = $connection->pop($queue);

    expect($retried)->toBeInstanceOf(RedisJob::class)
        ->and($retried->attempts())->toBe(2);

    $retried->delete();
});

it('holds a delayed job until it is due', function (): void {
    [$queue, $full] = contractQueue();
    $connection = redisQueue();

    $connection->later(300, new RecordingJob('delayed'), '', $queue);

    expect(zcard($full.':delayed'))->toBe(1)
        ->and($connection->pop($queue))->toBeNull()
        ->and($connection->size($queue))->toBe(1);
});

it('recovers a reserved job whose worker never acknowledged it', function (): void {
    [$queue, $full] = contractQueue();
    $connection = redisQueue(retryAfter: 1);
    $connection->push(new RecordingJob('abandoned'), '', $queue);

    $abandoned = $connection->pop($queue);

    expect($abandoned->attempts())->toBe(1)
        ->and(zcard($full.':reserved'))->toBe(1);

    // The reservation expires on the server; the next pop migrates it back.
    $recovered = Eventually::value(
        static fn (): ?RedisJob => $connection->pop($queue),
        static fn (?RedisJob $job): bool => $job !== null,
        5.0,
        'the reserved job to expire',
    );

    expect($recovered->getJobId())->toBe($abandoned->getJobId())
        ->and($recovered->attempts())->toBe(2);

    $recovered->delete();
});

it('runs a job in a separate worker process and removes it', function (): void {
    [$queue, $full] = contractQueue();
    $token = bin2hex(random_bytes(6));
    trackJob($token);
    redisQueue()->push(new RecordingJob($token), '', $queue);

    [$worker] = Concurrently::run($this->app, RedisContract::class, 1, ['op' => 'queue-work', 'queue' => $queue]);

    expect($worker->get('exit'))->toBe(0, (string) $worker->get('output'))
        ->and($worker->get('output'))->toContain('RecordingJob')
        ->and(Redis::connection()->get(RecordingJob::handledKey($token)))->toBe('1')
        ->and(redisQueue()->size($queue))->toBe(0)
        ->and(zcard($full.':reserved'))->toBe(0);
});

it('fails a job in a separate worker process, calls failed() and removes it', function (): void {
    [$queue, $full] = contractQueue();
    $token = bin2hex(random_bytes(6));
    trackJob($token);
    redisQueue()->push(new RecordingJob($token, fail: true), '', $queue);

    Concurrently::run($this->app, RedisContract::class, 1, ['op' => 'queue-work', 'queue' => $queue]);

    expect(Redis::connection()->get(RecordingJob::failedKey($token)))->toBe('RecordingJob failed on purpose')
        ->and(Redis::connection()->get(RecordingJob::handledKey($token)))->toBeNull()
        ->and(redisQueue()->size($queue))->toBe(0)
        ->and(zcard($full.':reserved'))->toBe(0)
        ->and(zcard($full.':delayed'))->toBe(0);
});
