<?php

declare(strict_types=1);

use App\Modules\Vendors\Application\ApproveVendor;
use App\Modules\Vendors\Application\ReactivateVendor;
use App\Modules\Vendors\Application\SuspendVendor;
use App\Modules\Vendors\Domain\InvalidVendorTransition;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorStatus;
use App\Shared\Audit\Actor;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Audit\AuditInsertFailure;
use Tests\Support\Database\InjectedQueryFailure;

/*
 * #35, ADR-0023 Decisions 4 to 6 and amendment B: ApproveVendor,
 * SuspendVendor and ReactivateVendor lock the row, change it through the
 * unchanged VendorLifecycle and record the change, in one transaction. These
 * run inside RefreshDatabase's transaction, so each orchestrator runs in a
 * savepoint and never retries here (see tests/Concurrency/ for the top
 * level).
 */

/** @return array<string, array{0: class-string, 1: VendorStatus, 2: VendorStatus, 3: string}> */
function auditedTransitions(): array
{
    return [
        'approve' => [ApproveVendor::class, VendorStatus::Pending, VendorStatus::Approved, 'vendor.approved'],
        'suspend' => [SuspendVendor::class, VendorStatus::Approved, VendorStatus::Suspended, 'vendor.suspended'],
        'reactivate' => [ReactivateVendor::class, VendorStatus::Suspended, VendorStatus::Approved, 'vendor.reactivated'],
    ];
}

/**
 * Store a vendor directly in $status. The query builder, not CreateVendor:
 * these tests are about the lifecycle orchestrators.
 */
function lifecycleVendorIn(VendorStatus $status, string $slug = 'acme-supplies'): int
{
    return DB::table('vendors')->insertGetId([
        'slug' => $slug,
        'name' => 'Acme Supplies',
        'status' => $status->value,
        'created_at' => '2026-01-01 00:00:00.000000',
        'updated_at' => '2026-01-01 00:00:00.000000',
    ]);
}

/** @param  class-string  $orchestrator */
function runTransition(string $orchestrator, int $vendorId, ?Actor $actor = null): Vendor
{
    return app($orchestrator)->handle($actor ?? Actor::console('vendors:approve'), $vendorId);
}

function storedStatus(int $vendorId): ?string
{
    return DB::table('vendors')->where('id', $vendorId)->value('status');
}

it('changes the status and writes one record with the actor, subject, before and after', function (string $orchestrator, VendorStatus $from, VendorStatus $to, string $action): void {
    $id = lifecycleVendorIn($from);

    $vendor = runTransition($orchestrator, $id, Actor::user(7, ip: '2001:db8::1'));

    $rows = DB::table('audit_log')->get();
    $row = (array) $rows->first();

    expect(storedStatus($id))->toBe($to->value)
        ->and($vendor->id)->toBe($id)
        ->and($vendor->status)->toBe($to)
        ->and($rows)->toHaveCount(1)
        ->and([$row['actor_type'], $row['actor_user_id'], $row['impersonator_user_id'], $row['actor_label'], $row['ip']])->toBe(['user', 7, null, null, '2001:db8::1'])
        ->and([$row['action'], $row['subject_type'], $row['subject_id']])->toBe([$action, 'vendor', $id])
        ->and(json_decode($row['before'], true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => $from->value])
        ->and(json_decode($row['after'], true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => $to->value]);
})->with(auditedTransitions());

it('records a console actor as provenance only', function (): void {
    runTransition(ApproveVendor::class, lifecycleVendorIn(VendorStatus::Pending), Actor::console('vendors:approve'));

    expect((array) DB::table('audit_log')->first(['actor_type', 'actor_user_id', 'actor_label']))
        ->toBe(['actor_type' => 'console', 'actor_user_id' => null, 'actor_label' => 'vendors:approve']);
});

it('refuses a transition from any other status and writes nothing', function (string $orchestrator, VendorStatus $from): void {
    foreach (VendorStatus::cases() as $status) {
        if ($status === $from) {
            continue;
        }

        $id = lifecycleVendorIn($status, "acme-{$status->value}");

        expect(fn () => runTransition($orchestrator, $id))->toThrow(InvalidVendorTransition::class, "its status is {$status->value}, not {$from->value}");
        expect(storedStatus($id))->toBe($status->value);
    }

    expect(DB::table('audit_log')->count())->toBe(0);
})->with(auditedTransitions());

it('refuses a repeated transition, keeping the first record only', function (string $orchestrator, VendorStatus $from, VendorStatus $to): void {
    $id = lifecycleVendorIn($from);
    runTransition($orchestrator, $id);

    expect(fn () => runTransition($orchestrator, $id))->toThrow(InvalidVendorTransition::class);

    expect(storedStatus($id))->toBe($to->value)
        ->and(DB::table('audit_log')->count())->toBe(1);
})->with(auditedTransitions());

it('refuses a missing Vendor and writes nothing', function (string $orchestrator): void {
    expect(fn () => runTransition($orchestrator, 999_999))
        ->toThrow(ModelNotFoundException::class, 'No query results for model [App\Modules\Vendors\Domain\Vendor] 999999');

    expect(DB::table('audit_log')->count())->toBe(0);
})->with(auditedTransitions());

it('rolls the transition back when the audit insert fails, and lets the failure through', function (string $orchestrator, VendorStatus $from): void {
    $id = lifecycleVendorIn($from);
    $failure = AuditInsertFailure::injectOnce();
    $level = DB::transactionLevel();

    try {
        runTransition($orchestrator, $id);
        $caught = null;
    } catch (Throwable $caught) {
    }

    expect($failure->thrown)->toBeInstanceOf(QueryException::class)
        ->and($caught)->toBe($failure->thrown)
        ->and(DB::transactionLevel())->toBe($level)
        ->and(storedStatus($id))->toBe($from->value)
        ->and(DB::table('audit_log')->count())->toBe(0);
})->with(auditedTransitions());

it('leaves neither change when the caller rolls back after it succeeded', function (string $orchestrator, VendorStatus $from): void {
    $id = lifecycleVendorIn($from);

    expect(fn () => DB::transaction(function () use ($orchestrator, $id): void {
        runTransition($orchestrator, $id);

        throw new RuntimeException('The caller gave up.');
    }))->toThrow(RuntimeException::class);

    expect(storedStatus($id))->toBe($from->value)
        ->and(DB::table('audit_log')->count())->toBe(0);
})->with(auditedTransitions());

it('discards both when its savepoint rolls back, while the outer transaction keeps its own change', function (): void {
    $id = lifecycleVendorIn(VendorStatus::Pending);

    DB::transaction(function () use ($id): void {
        runTransition(ApproveVendor::class, $id);

        try {
            DB::transaction(function () use ($id): void {
                runTransition(SuspendVendor::class, $id);

                throw new RuntimeException('The inner step failed.');
            });
        } catch (RuntimeException) {
        }
    });

    expect(storedStatus($id))->toBe('approved')
        ->and(DB::table('audit_log')->pluck('action')->all())->toBe(['vendor.approved']);
});

it('takes before from the database, not from a stale model the caller holds', function (): void {
    $id = lifecycleVendorIn(VendorStatus::Pending);
    $stale = Vendor::query()->findOrFail($id);

    // Another change lands after the caller loaded its copy.
    runTransition(ApproveVendor::class, $id);
    DB::table('audit_log')->delete();

    $vendor = runTransition(SuspendVendor::class, $stale->id);

    expect(json_decode((string) DB::table('audit_log')->value('before'), true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => 'approved'])
        ->and($vendor)->not->toBe($stale)
        // The caller's copy is neither read for the snapshot nor changed.
        ->and($stale->status)->toBe(VendorStatus::Pending)
        ->and($stale->isDirty())->toBeFalse();
});

it('locks the vendors row before reading before, and inserts the audit record last', function (): void {
    $id = lifecycleVendorIn(VendorStatus::Pending);
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtok($query->sql, ' ').(str_contains($query->sql, 'for update') ? ' for update' : '').' '.(preg_match('/(?:from|update|into) `(\w+)`/', $query->sql, $table) === 1 ? $table[1] : '');
    });

    runTransition(ApproveVendor::class, $id);

    expect(array_values(array_filter($statements, fn (string $statement): bool => ! str_starts_with($statement, 'savepoint') && ! str_starts_with($statement, 'release'))))->toBe([
        'select for update vendors',
        'update vendors',
        'select vendors',
        'insert audit_log',
    ]);
});

it('does not retry inside a caller\'s transaction: a deadlock reaches the caller', function (): void {
    $id = lifecycleVendorIn(VendorStatus::Pending);
    $deadlock = InjectedQueryFailure::on('insert into `audit_log`', InjectedQueryFailure::DEADLOCK, times: 3);

    expect(fn () => runTransition(ApproveVendor::class, $id))->toThrow(DeadlockException::class);

    expect($deadlock->thrown)->toBe(1)
        ->and(storedStatus($id))->toBe('pending')
        ->and(DB::table('audit_log')->count())->toBe(0);
});
