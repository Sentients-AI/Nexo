<?php

declare(strict_types=1);

use App\Modules\Vendors\Application\ApproveVendor;
use App\Modules\Vendors\Domain\VendorStatus;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\WorkerResult;
use Tests\Concurrency\Scenarios\RunAuditedVendorOperation;

/*
 * #35, ADR-0023 testing implication 6 and amendment E: audited orchestrators
 * under real contention. Separate worker processes, each with its own
 * connection, run the same orchestrator at once as the outermost
 * transaction. Exactly one may succeed, the others are refused with the
 * domain exception (never exhausted retries or another error, which would
 * fail the worker), and exactly one audit record exists, with before taken
 * under the lock.
 *
 * Fixtures are committed (DatabaseTruncation). Each test removes the
 * vendors and audit records it made, by its slug prefix and actor label,
 * whether it passed or failed; Concurrently::run() returns or throws only
 * after every worker has stopped.
 */

const AUDITED_WORKER_LABEL = 'test:audited-vendor-workers';

afterEach(function (): void {
    DB::table('audit_log')->where('actor_label', AUDITED_WORKER_LABEL)->delete();
    DB::table('vendors')->where('slug', 'like', 'audited-workers-%')->delete();
});

function workerVendorIn(VendorStatus $status): int
{
    return DB::table('vendors')->insertGetId([
        'slug' => 'audited-workers-'.bin2hex(random_bytes(4)),
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
function auditedWinnersAndLosers(array $results): array
{
    return [
        array_values(array_filter($results, fn (WorkerResult $r): bool => $r->get('won') === true)),
        array_values(array_filter($results, fn (WorkerResult $r): bool => $r->get('won') === false)),
    ];
}

/** @return list<array<string, mixed>> */
function workerAuditRows(): array
{
    return DB::table('audit_log')->where('actor_label', AUDITED_WORKER_LABEL)->get()->map(fn (object $row): array => (array) $row)->all();
}

it('lets exactly one of several concurrent transitions win, with exactly one record', function (string $operation, VendorStatus $from, VendorStatus $to, string $action, int $workers): void {
    $id = workerVendorIn($from);

    $results = Concurrently::run(app(), RunAuditedVendorOperation::class, workers: $workers, arguments: [
        'operation' => $operation,
        'vendor_id' => $id,
        'label' => AUDITED_WORKER_LABEL,
    ]);

    [$winners, $losers] = auditedWinnersAndLosers($results);
    $audit = workerAuditRows();

    expect($results)->toHaveCount($workers)
        ->and($winners)->toHaveCount(1)
        ->and($losers)->toHaveCount($workers - 1)
        ->and(DB::table('vendors')->where('id', $id)->value('status'))->toBe($to->value)
        ->and($audit)->toHaveCount(1)
        ->and([$audit[0]['action'], $audit[0]['subject_id']])->toBe([$action, $id])
        ->and(json_decode($audit[0]['before'], true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => $from->value])
        ->and(json_decode($audit[0]['after'], true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => $to->value]);

    foreach ($losers as $loser) {
        // Refused by the lifecycle after waiting for the lock, seeing the winner's commit.
        expect($loser->get('refusal'))->toBe('transition')
            ->and($loser->get('actual_status'))->toBe($to->value);
    }

    foreach ($results as $result) {
        // Progress without exhausted retries: the lock serialises them.
        expect($result->get('attempts'))->toBe(1);
    }
})->with([
    'two approvals' => ['approve', VendorStatus::Pending, VendorStatus::Approved, 'vendor.approved', 2],
    'four approvals' => ['approve', VendorStatus::Pending, VendorStatus::Approved, 'vendor.approved', 4],
    'two suspensions' => ['suspend', VendorStatus::Approved, VendorStatus::Suspended, 'vendor.suspended', 2],
    'two reactivations' => ['reactivate', VendorStatus::Suspended, VendorStatus::Approved, 'vendor.reactivated', 2],
])->repeat(3);

it('lets exactly one of several concurrent registrations of one slug win, with exactly one record', function (int $workers): void {
    $slug = 'audited-workers-'.bin2hex(random_bytes(4));

    $results = Concurrently::run(app(), RunAuditedVendorOperation::class, workers: $workers, arguments: [
        'operation' => 'register',
        'slug' => $slug,
        'label' => AUDITED_WORKER_LABEL,
    ]);

    [$winners, $losers] = auditedWinnersAndLosers($results);
    $vendors = DB::table('vendors')->where('slug', $slug)->get();
    $audit = workerAuditRows();

    expect($results)->toHaveCount($workers)
        ->and($winners)->toHaveCount(1)
        ->and($losers)->toHaveCount($workers - 1)
        ->and($vendors)->toHaveCount(1)
        ->and($vendors->first()?->id)->toBe($winners[0]->get('id'))
        ->and($audit)->toHaveCount(1)
        ->and([$audit[0]['action'], $audit[0]['subject_id'], $audit[0]['before']])->toBe(['vendor.created', $winners[0]->get('id'), null]);

    foreach ($losers as $loser) {
        expect($loser->get('refusal'))->toBe('duplicate');
    }
})->with([2, 4])->repeat(3);

it('control: an audit record written outside the change\'s transaction survives the refusal, and the test would see it', function (): void {
    $id = workerVendorIn(VendorStatus::Pending);

    $results = Concurrently::run(app(), RunAuditedVendorOperation::class, workers: 2, arguments: [
        'operation' => 'approve',
        'vendor_id' => $id,
        'label' => AUDITED_WORKER_LABEL,
        'control' => true,
    ]);

    [$winners, $losers] = auditedWinnersAndLosers($results);

    expect($winners)->toHaveCount(1)
        ->and($losers)->toHaveCount(1)
        // Two records for one change: what the assertion above rules out.
        ->and(workerAuditRows())->toHaveCount(2)
        ->and(ApproveVendor::ACTION)->toBe('vendor.approved');
});
