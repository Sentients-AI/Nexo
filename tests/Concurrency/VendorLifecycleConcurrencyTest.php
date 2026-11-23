<?php

declare(strict_types=1);

use App\Modules\Vendors\Domain\VendorStatus;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\WorkerResult;
use Tests\Concurrency\Scenarios\TransitionVendor;

/*
 * INV-14, the Vendor lifecycle milestone's acceptance criterion: concurrent
 * transitions of one vendor cannot both succeed. Separate worker processes,
 * each with its own connection, hold the same loaded vendor and run the same
 * operation at once (ADR-0009 §3).
 *
 * The fixtures are committed so the workers can see them. DatabaseTruncation
 * truncates before each test, not after, so remove them once each test is
 * done: a RefreshDatabase test run later in the same process would otherwise
 * find them.
 */

afterEach(function (): void {
    DB::table('vendors')->delete();
});

function vendorStoredAs(VendorStatus $status): int
{
    return DB::table('vendors')->insertGetId([
        'slug' => 'acme-supplies',
        'name' => 'Acme Supplies',
        'status' => $status->value,
        'created_at' => '2026-01-01 00:00:00.000000',
        'updated_at' => '2026-01-01 00:00:00.000000',
    ]);
}

/**
 * @param  list<WorkerResult>  $results
 * @return array{0: list<WorkerResult>, 1: list<WorkerResult>}
 */
function winnersAndLosers(array $results): array
{
    return [
        array_values(array_filter($results, fn (WorkerResult $r): bool => $r->get('won') === true)),
        array_values(array_filter($results, fn (WorkerResult $r): bool => $r->get('won') === false)),
    ];
}

it('lets exactly one of several concurrent identical operations win', function (string $operation, VendorStatus $from, VendorStatus $to, int $workers): void {
    $id = vendorStoredAs($from);

    $results = Concurrently::run(app(), TransitionVendor::class, workers: $workers, arguments: [
        'vendor_id' => $id,
        'operation' => $operation,
    ]);

    [$winners, $losers] = winnersAndLosers($results);
    $row = DB::table('vendors')->where('id', $id)->first();

    expect($winners)->toHaveCount(1)
        ->and($losers)->toHaveCount($workers - 1)
        ->and($row?->status)->toBe($to->value)
        // The losers wrote nothing: the row holds the winner's write, not a later one.
        ->and($row?->updated_at)->toBe($winners[0]->get('written_at'));

    foreach ($losers as $loser) {
        // Each loser saw the winner's committed status, not the one it had loaded.
        expect($loser->get('actual_status'))->toBe($to->value);
    }
})->with([
    'two approvals' => ['approve', VendorStatus::Pending, VendorStatus::Approved, 2],
    'two suspensions' => ['suspend', VendorStatus::Approved, VendorStatus::Suspended, 2],
    'four approvals' => ['approve', VendorStatus::Pending, VendorStatus::Approved, 4],
])->repeat(3);

it('control: a read-then-write lets both operations "succeed", and the test would see it', function (): void {
    $id = vendorStoredAs(VendorStatus::Pending);

    $results = Concurrently::run(app(), TransitionVendor::class, workers: 2, arguments: [
        'vendor_id' => $id,
        'operation' => 'approve',
        'naive' => true,
    ]);

    [$winners] = winnersAndLosers($results);

    expect($winners)->toHaveCount(2)
        ->and(DB::table('vendors')->where('id', $id)->value('status'))->toBe('approved');
});
