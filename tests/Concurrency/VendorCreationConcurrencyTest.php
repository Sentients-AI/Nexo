<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\WorkerResult;
use Tests\Concurrency\Scenarios\CreateVendorWithSlug;

/*
 * #29, owner decision D2: concurrent creations of one slug cannot both
 * succeed, because uniqueness is the database's, not a check before the
 * insert. Separate worker processes, each with its own connection, create a
 * Vendor with the same slug at once (ADR-0009 §3).
 *
 * The workers commit, so each test removes the vendors it created, by slug,
 * whether it passed or failed: a RefreshDatabase test run later in the same
 * process would otherwise find them. Concurrently::run() returns, or throws,
 * only after every worker has stopped.
 */

const CONCURRENT_VENDOR_SLUG = 'acme-concurrent';

afterEach(function (): void {
    DB::table('vendors')->where('slug', CONCURRENT_VENDOR_SLUG)->delete();
});

it('lets exactly one of several concurrent creations of one slug succeed', function (int $workers): void {
    $results = Concurrently::run(app(), CreateVendorWithSlug::class, workers: $workers, arguments: [
        'slug' => CONCURRENT_VENDOR_SLUG,
    ]);

    $winners = array_values(array_filter($results, fn (WorkerResult $r): bool => $r->get('won') === true));
    $losers = array_values(array_filter($results, fn (WorkerResult $r): bool => $r->get('won') === false));
    $rows = DB::table('vendors')->where('slug', CONCURRENT_VENDOR_SLUG)->get();

    expect($winners)->toHaveCount(1)
        ->and($losers)->toHaveCount($workers - 1)
        ->and($rows)->toHaveCount(1)
        // The row is the winner's: its id and its name, not a loser's.
        ->and($rows[0]->id)->toBe($winners[0]->get('id'))
        ->and($rows[0]->name)->toBe($winners[0]->get('name'))
        ->and($rows[0]->status)->toBe('pending');

    foreach ($losers as $loser) {
        // Each loser was refused by the slug's unique index, and told so.
        expect($loser->get('index'))->toBe('vendors_slug_unique');
    }
})->with([
    'two workers' => [2],
    'four workers' => [4],
])->repeat(3);
