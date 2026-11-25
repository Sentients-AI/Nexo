<?php

declare(strict_types=1);

use App\Modules\Vendors\Application\ApproveVendor;
use App\Modules\Vendors\Application\RegisterVendor;
use App\Modules\Vendors\Domain\InvalidVendorTransition;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use App\Modules\Vendors\Domain\VendorStatus;
use App\Shared\Audit\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\InjectedQueryFailure;

/*
 * #35, ADR-0023 testing implications 7 and 8, ADR-0011 Decision 10: retries
 * at the outermost transaction. These run without a wrapping test
 * transaction (DatabaseTruncation), so the orchestrator owns the real
 * transaction and its DB::transaction(..., 3) retries a concurrency error.
 * The errors are injected deterministically (InjectedQueryFailure); real
 * contention is in AuditedVendorConcurrencyTest.
 *
 * Attempts are counted by the locking read each lifecycle attempt starts
 * with, or by RegisterVendor's insert into vendors. Each test removes its
 * vendors and audit records, by slug prefix and actor label, whether it
 * passed or failed.
 */

const AUDITED_RETRY_LABEL = 'test:audited-vendor-retry';

afterEach(function (): void {
    foreach ([DB::connection(), DB::connection('mysql_locks')] as $connection) {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
    }

    DB::table('audit_log')->where('actor_label', AUDITED_RETRY_LABEL)->delete();
    DB::table('vendors')->where('slug', 'like', 'audited-retry-%')->delete();
});

function retryVendorIn(VendorStatus $status): int
{
    return DB::table('vendors')->insertGetId([
        'slug' => 'audited-retry-'.bin2hex(random_bytes(4)),
        'name' => 'Acme Supplies',
        'status' => $status->value,
        'created_at' => '2026-01-01 00:00:00.000000',
        'updated_at' => '2026-01-01 00:00:00.000000',
    ]);
}

function retryActor(): Actor
{
    return Actor::console(AUDITED_RETRY_LABEL);
}

/** Count the statements on the default connection that start an attempt. */
function countAttempts(): ArrayObject
{
    $attempts = new ArrayObject(['count' => 0]);

    DB::connection()->beforeExecuting(function (string $query) use ($attempts): void {
        if (str_ends_with($query, 'for update') || str_starts_with($query, 'insert into `vendors`')) {
            $attempts['count']++;
        }
    });

    return $attempts;
}

/** @return list<array<string, mixed>> */
function retryAuditRows(): array
{
    return DB::table('audit_log')->where('actor_label', AUDITED_RETRY_LABEL)->get()->map(fn (object $row): array => (array) $row)->all();
}

it('retries a concurrency error from scratch, and writes one record from the reloaded state', function (string $prefix, string $error): void {
    $id = retryVendorIn(VendorStatus::Pending);
    $attempts = countAttempts();
    $failure = InjectedQueryFailure::on($prefix, $error);

    $vendor = app(ApproveVendor::class)->handle(retryActor(), $id);
    $audit = retryAuditRows();

    // The first attempt approved its in-memory model before failing. Had the
    // retry reused that model, before would read approved.
    expect($failure->thrown)->toBe(1)
        ->and($attempts['count'])->toBe(2)
        ->and(DB::transactionLevel())->toBe(0)
        ->and($vendor->status)->toBe(VendorStatus::Approved)
        ->and(DB::table('vendors')->where('id', $id)->value('status'))->toBe('approved')
        ->and($audit)->toHaveCount(1)
        ->and(json_decode($audit[0]['before'], true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => 'pending'])
        ->and(json_decode($audit[0]['after'], true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => 'approved']);
})->with([
    'deadlock on the audit insert' => ['insert into `audit_log`', InjectedQueryFailure::DEADLOCK],
    'deadlock on the status update' => ['update `vendors`', InjectedQueryFailure::DEADLOCK],
    'lock-wait timeout on the locking read' => ['select * from `vendors`', InjectedQueryFailure::LOCK_WAIT_TIMEOUT],
]);

it('sees a change committed between attempts: the retry reloads, and refuses', function (): void {
    $id = retryVendorIn(VendorStatus::Pending);
    $attempts = countAttempts();
    $done = false;

    // The first locking read times out, and meanwhile another connection approves the vendor.
    DB::connection()->beforeExecuting(function (string $query, array $bindings, $connection) use (&$done, $id): void {
        if ($done || ! str_ends_with($query, 'for update')) {
            return;
        }

        $done = true;
        DB::connection('mysql_locks')->table('vendors')->where('id', $id)->update(['status' => 'approved']);

        throw new QueryException((string) $connection->getName(), $query, $bindings, new PDOException(InjectedQueryFailure::LOCK_WAIT_TIMEOUT));
    });

    expect(fn () => app(ApproveVendor::class)->handle(retryActor(), $id))
        ->toThrow(InvalidVendorTransition::class, 'its status is approved, not pending');

    // Two attempts: the timeout was retried; the refusal was not.
    expect($attempts['count'])->toBe(2)
        ->and(retryAuditRows())->toBe([]);
});

it('surfaces the concurrency error once retries are exhausted, writing nothing', function (): void {
    $id = retryVendorIn(VendorStatus::Pending);
    $attempts = countAttempts();
    $failure = InjectedQueryFailure::on('insert into `audit_log`', InjectedQueryFailure::DEADLOCK, times: 10);

    expect(fn () => app(ApproveVendor::class)->handle(retryActor(), $id))
        ->toThrow(QueryException::class, 'Deadlock found when trying to get lock');

    expect($failure->thrown)->toBe(ApproveVendor::ATTEMPTS)
        ->and($attempts['count'])->toBe(ApproveVendor::ATTEMPTS)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::table('vendors')->where('id', $id)->value('status'))->toBe('pending')
        ->and(retryAuditRows())->toBe([]);
});

it('does not retry a refused transition', function (): void {
    $id = retryVendorIn(VendorStatus::Approved);
    $attempts = countAttempts();

    expect(fn () => app(ApproveVendor::class)->handle(retryActor(), $id))->toThrow(InvalidVendorTransition::class);

    expect($attempts['count'])->toBe(1)
        ->and(retryAuditRows())->toBe([]);
});

it('retries a registration from the input, leaving one Vendor and one record', function (): void {
    $slug = 'audited-retry-'.bin2hex(random_bytes(4));
    $attempts = countAttempts();
    $failure = InjectedQueryFailure::on('insert into `audit_log`', InjectedQueryFailure::DEADLOCK);

    $vendor = app(RegisterVendor::class)->handle(retryActor(), 'Acme Supplies', $slug);
    $audit = retryAuditRows();

    // The first attempt's insert was rolled back, so the retry's same slug is no duplicate.
    expect($failure->thrown)->toBe(1)
        ->and($attempts['count'])->toBe(2)
        ->and(DB::table('vendors')->where('slug', $slug)->pluck('id')->all())->toBe([$vendor->id])
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]['subject_id'])->toBe($vendor->id);
});

it('surfaces exhausted registration retries, writing nothing', function (): void {
    $slug = 'audited-retry-'.bin2hex(random_bytes(4));
    InjectedQueryFailure::on('insert into `audit_log`', InjectedQueryFailure::DEADLOCK, times: 10);

    expect(fn () => app(RegisterVendor::class)->handle(retryActor(), 'Acme Supplies', $slug))
        ->toThrow(QueryException::class, 'Deadlock found when trying to get lock');

    expect(DB::table('vendors')->where('slug', $slug)->count())->toBe(0)
        ->and(retryAuditRows())->toBe([]);
});

it('does not retry a duplicate slug', function (): void {
    $slug = 'audited-retry-'.bin2hex(random_bytes(4));
    app(RegisterVendor::class)->handle(retryActor(), 'Acme Supplies', $slug);
    $attempts = countAttempts();

    expect(fn () => app(RegisterVendor::class)->handle(retryActor(), 'Another Acme', $slug))->toThrow(VendorSlugAlreadyExists::class);

    expect($attempts['count'])->toBe(1)
        ->and(retryAuditRows())->toHaveCount(1);
});

it('leaves the retry to the caller when the caller owns the outermost transaction', function (): void {
    $id = retryVendorIn(VendorStatus::Pending);
    $outerAttempts = 0;
    $failure = InjectedQueryFailure::on('insert into `audit_log`', InjectedQueryFailure::DEADLOCK);

    DB::transaction(function () use ($id, &$outerAttempts): void {
        $outerAttempts++;
        app(ApproveVendor::class)->handle(retryActor(), $id);
    }, 3);

    // The nested orchestrator threw DeadlockException after one attempt; the
    // caller's transaction retried the whole unit.
    expect($failure->thrown)->toBe(1)
        ->and($outerAttempts)->toBe(2)
        ->and(DB::table('vendors')->where('id', $id)->value('status'))->toBe('approved')
        ->and(retryAuditRows())->toHaveCount(1);
});
