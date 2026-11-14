<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\WorkerResult;
use Tests\Concurrency\Scenarios\AcquireDatabaseLock;
use Tests\Support\Database\LockConnection;

/*
 * S-02 (M6-D-02 criterion 5): two worker processes contending for one
 * Cache::lock key on the `database` store over mysql_locks get exactly one
 * acquisition, while the attempts overlap; after release the lock can be
 * taken again; cache_locks ends empty.
 */

it('gives exactly one of two contending processes the lock', function (): void {
    $database = LockConnection::serverDatabase(DB::connection());

    $results = Concurrently::run(app(), AcquireDatabaseLock::class, workers: 2, arguments: [
        'key' => 's02-contention',
        'hold_ms' => 1500,
    ]);

    $winners = array_values(array_filter($results, fn (WorkerResult $r): bool => $r->get('acquired') === true));
    $losers = array_values(array_filter($results, fn (WorkerResult $r): bool => $r->get('acquired') === false));

    expect($winners)->toHaveCount(1)
        ->and($losers)->toHaveCount(1);

    [$winner, $loser] = [$winners[0], $losers[0]];

    // Real contention: the loser was refused while the winner held the lock.
    expect($loser->get('attempt_finished_at'))->toBeLessThan($winner->get('released_at'));

    // Separate processes, both on this process's database, over mysql_locks.
    foreach ($results as $result) {
        expect($result->get('lock_connection'))->toBe('mysql_locks')
            ->and($result->get('lock_database'))->toBe($database);
    }

    // Reacquisition after release.
    $lock = Cache::lock('s02-contention', 10);
    expect($lock->get())->toBeTrue();
    $lock->release();

    expect(DB::connection('mysql_locks')->table('cache_locks')->count())->toBe(0);
})->repeat(3);

it('gives exactly one of several contending processes the lock', function (): void {
    $results = Concurrently::run(app(), AcquireDatabaseLock::class, workers: 4, arguments: [
        'key' => 's02-contention-4',
        'hold_ms' => 1500,
    ]);

    $acquired = array_map(fn (WorkerResult $r): mixed => $r->get('acquired'), $results);
    sort($acquired);

    expect($acquired)->toBe([false, false, false, true]);
});

it('control: a per-process lock store lets both processes in, and the test would see it', function (): void {
    $results = Concurrently::run(app(), AcquireDatabaseLock::class, workers: 2, arguments: [
        'key' => 's02-control',
        'hold_ms' => 1500,
        'store' => 'array',
    ]);

    [$a, $b] = $results;

    expect($a->get('acquired'))->toBeTrue()
        ->and($b->get('acquired'))->toBeTrue()
        // Both held it at the same time.
        ->and($a->get('attempt_finished_at'))->toBeLessThan($b->get('released_at'))
        ->and($b->get('attempt_finished_at'))->toBeLessThan($a->get('released_at'));
});
