<?php

declare(strict_types=1);

use App\Modules\Vendors\Application\RegisterVendor;
use App\Modules\Vendors\Domain\InvalidVendorDetails;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use App\Modules\Vendors\Domain\VendorStatus;
use App\Shared\Audit\Actor;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Audit\AuditInsertFailure;
use Tests\Support\Database\InjectedQueryFailure;

/*
 * #35, ADR-0023 Decisions 4 to 6: RegisterVendor creates a Vendor and its
 * vendor.created record in one transaction. These run inside
 * RefreshDatabase's transaction, so RegisterVendor runs in a savepoint and
 * never retries here. Commit, retry and contention at the top level are in
 * tests/Concurrency/AuditedVendorRetryTest.php and
 * AuditedVendorConcurrencyTest.php.
 */

function registerVendor(string $slug = 'acme-supplies', string $name = 'Acme Supplies', ?Actor $actor = null): int
{
    return app(RegisterVendor::class)->handle($actor ?? Actor::console('vendors:create'), $name, $slug)->id;
}

/** @return array<string, mixed> */
function onlyAuditRow(): array
{
    $rows = DB::table('audit_log')->get();

    expect($rows)->toHaveCount(1);

    return (array) $rows->first();
}

function vendorCount(): int
{
    return DB::table('vendors')->count();
}

function auditCount(): int
{
    return DB::table('audit_log')->count();
}

it('creates one pending Vendor and one vendor.created record', function (): void {
    Carbon::setTestNow('2026-11-25 12:00:00.123456');

    $id = registerVendor('acme-supplies', '  Acme Supplies  ');
    $row = onlyAuditRow();

    expect(DB::table('vendors')->where('id', $id)->first(['slug', 'name', 'status']))
        ->toEqual((object) ['slug' => 'acme-supplies', 'name' => 'Acme Supplies', 'status' => 'pending'])
        ->and(vendorCount())->toBe(1)
        ->and($row['occurred_at'])->toBe('2026-11-25 12:00:00.123456')
        ->and($row['actor_type'])->toBe('console')
        ->and($row['actor_user_id'])->toBeNull()
        ->and($row['impersonator_user_id'])->toBeNull()
        ->and($row['actor_label'])->toBe('vendors:create')
        ->and($row['action'])->toBe('vendor.created')
        ->and($row['subject_type'])->toBe('vendor')
        ->and($row['subject_id'])->toBe($id)
        ->and($row['before'])->toBeNull()
        ->and(json_decode($row['after'], true, flags: JSON_THROW_ON_ERROR))->toBe(['name' => 'Acme Supplies', 'slug' => 'acme-supplies', 'status' => 'pending']);
});

it('returns the created Vendor, pending', function (): void {
    $vendor = app(RegisterVendor::class)->handle(Actor::console('vendors:create'), 'Acme', 'acme');

    expect($vendor->exists)->toBeTrue()
        ->and($vendor->status)->toBe(VendorStatus::Pending)
        ->and($vendor->slug)->toBe('acme');
});

it('records a user actor exactly as given', function (): void {
    registerVendor(actor: Actor::user(7, impersonatorUserId: 3, ip: '203.0.113.9'));

    $row = onlyAuditRow();

    expect([$row['actor_type'], $row['actor_user_id'], $row['impersonator_user_id'], $row['actor_label'], $row['ip']])
        ->toBe(['user', 7, 3, null, '203.0.113.9']);
});

it('writes nothing for invalid details', function (string $slug, string $name): void {
    expect(fn () => registerVendor($slug, $name))->toThrow(InvalidVendorDetails::class);

    expect(vendorCount())->toBe(0)->and(auditCount())->toBe(0);
})->with([
    'bad slug' => ['Acme Supplies', 'Acme'],
    'empty name' => ['acme', '   '],
]);

it('writes no second Vendor or record for a duplicate slug', function (): void {
    registerVendor('acme-cafe');

    expect(fn () => registerVendor('acme-cafe', 'Another Acme'))->toThrow(VendorSlugAlreadyExists::class);

    expect(vendorCount())->toBe(1)
        ->and(auditCount())->toBe(1)
        ->and(DB::table('audit_log')->value('after'))->toContain('Acme Supplies');
});

it('lets an unexpected database error through untranslated, writing nothing', function (): void {
    InjectedQueryFailure::on('insert into `vendors`', 'SQLSTATE[HY000]: General error: 1030 Got error from storage engine');

    expect(fn () => registerVendor())->toThrow(QueryException::class, 'Got error from storage engine');

    expect(vendorCount())->toBe(0)->and(auditCount())->toBe(0);
});

it('rolls the Vendor back when the audit insert fails, and lets the failure through', function (): void {
    $failure = AuditInsertFailure::injectOnce();
    $level = DB::transactionLevel();

    try {
        registerVendor();
        $caught = null;
    } catch (Throwable $caught) {
    }

    expect($failure->thrown)->toBeInstanceOf(QueryException::class)
        ->and($caught)->toBe($failure->thrown)
        ->and(DB::transactionLevel())->toBe($level)
        ->and(vendorCount())->toBe(0)
        ->and(auditCount())->toBe(0);
});

it('leaves neither row when the caller rolls back after it succeeded', function (): void {
    expect(fn () => DB::transaction(function (): void {
        registerVendor();

        throw new RuntimeException('The caller gave up.');
    }))->toThrow(RuntimeException::class, 'The caller gave up.');

    expect(vendorCount())->toBe(0)->and(auditCount())->toBe(0);
});

it('leaves neither row when its savepoint rolls back, and the outer transaction continues', function (): void {
    DB::transaction(function (): void {
        registerVendor('kept-vendor');

        try {
            DB::transaction(function (): void {
                registerVendor('discarded-vendor');

                throw new RuntimeException('The inner step failed.');
            });
        } catch (RuntimeException) {
        }
    });

    expect(DB::table('vendors')->pluck('slug')->all())->toBe(['kept-vendor'])
        ->and(auditCount())->toBe(1);
});

it('does not retry inside a caller\'s transaction: a deadlock reaches the caller', function (): void {
    $deadlock = InjectedQueryFailure::on('insert into `audit_log`', InjectedQueryFailure::DEADLOCK, times: 3);

    expect(DB::transactionLevel())->toBeGreaterThan(0)
        ->and(fn () => registerVendor())->toThrow(DeadlockException::class);

    // One attempt only; the outermost transaction (here, the test's) decides.
    expect($deadlock->thrown)->toBe(1)
        ->and(vendorCount())->toBe(0)
        ->and(auditCount())->toBe(0);
});
