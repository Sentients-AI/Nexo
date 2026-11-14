<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Scenarios\CleanLocksWhileAnotherHolds;
use Tests\Support\Database\ProbeDatabases;

/*
 * ADR-0009 §3, refinement 3, across real processes: one process's lock
 * cleanup never removes another process's lock. Two probe databases stand in
 * for two --parallel process databases.
 */

beforeEach(function (): void {
    $this->probes = new ProbeDatabases(DB::connection());

    foreach ([ProbeDatabases::LOCKS_A, ProbeDatabases::LOCKS_B] as $name) {
        $this->probes->drop($name);
        $this->probes->createWithLockTable($name);
    }

    DB::table(DB::raw('`'.ProbeDatabases::LOCKS_B.'`.`cache_locks`'))
        ->insert(['key' => 'b-own-leftover', 'owner' => 'x', 'expiration' => time() + 300]);
});

afterEach(function (): void {
    foreach ([ProbeDatabases::LOCKS_A, ProbeDatabases::LOCKS_B] as $name) {
        $this->probes->drop($name);
    }
});

it('cleans only its own database while another process holds a lock', function (): void {
    [$holder, $cleaner] = Concurrently::run(app(), CleanLocksWhileAnotherHolds::class, workers: 2, workerEnvironment: [
        0 => ['DB_DATABASE' => ProbeDatabases::LOCKS_A],
        1 => ['DB_DATABASE' => ProbeDatabases::LOCKS_B],
    ]);

    expect($holder->get('database'))->toBe(ProbeDatabases::LOCKS_A)
        ->and($holder->get('acquired'))->toBeTrue()
        ->and($cleaner->get('database'))->toBe(ProbeDatabases::LOCKS_B)
        // The cleaner removed its own leftover lock...
        ->and($cleaner->get('deleted'))->toBe(1)
        // ...and the other process's lock was still there afterwards.
        ->and($holder->get('rows_after_other_cleaned'))->toBe(1);
});
