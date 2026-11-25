<?php

declare(strict_types=1);

use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditOutsideTransaction;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Audit\AuditInsertFailure;

/*
 * #34, ADR-0023 Decision 4: the recorder's transaction protocol at the top
 * level, where a commit or rollback is real. It refuses to write outside a
 * transaction, including when only another connection has one; it never ends
 * the caller's transaction; and a business change and its record commit or
 * roll back together, also when the audit insert fails.
 *
 * The business change is a stand-in written by the test (a users row), not a
 * Vendor operation: the audited Vendor orchestrators are #35.
 *
 * This suite runs without a wrapping test transaction (DatabaseTruncation),
 * so these writes are real commits. Each test ends any transaction it left
 * open and removes its rows, whether it passed or failed.
 */

const AUDIT_PROBE_SUBJECT = 'audit_probe';

afterEach(function (): void {
    foreach ([DB::connection(), DB::connection('mysql_locks')] as $connection) {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
    }

    DB::table('audit_log')->where('subject_type', AUDIT_PROBE_SUBJECT)->delete();
    DB::table('users')->where('email', 'like', 'audit-probe-%@example.com')->delete();
});

function recordProbeAudit(int $subjectId = 1): void
{
    app(AuditRecorder::class)->record(Actor::console('vendors:create'), 'probe.changed', AUDIT_PROBE_SUBJECT, $subjectId, null, ['name' => 'Changed']);
}

function probeAuditRows(): int
{
    return DB::table('audit_log')->where('subject_type', AUDIT_PROBE_SUBJECT)->count();
}

/** The representative business change: insert a users row, in whatever transaction is open. */
function probeBusinessChange(): int
{
    return DB::table('users')->insertGetId([
        'name' => 'Audit Probe',
        'email' => 'audit-probe-'.bin2hex(random_bytes(4)).'@example.com',
        'password' => 'not-a-real-hash',
    ]);
}

function probeUsers(): int
{
    return DB::table('users')->where('email', 'like', 'audit-probe-%@example.com')->count();
}

it('refuses to write outside a transaction, and writes nothing', function (): void {
    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => recordProbeAudit())
        ->toThrow(AuditOutsideTransaction::class, "AuditRecorder::record() must run inside the caller's database transaction on the [mysql] connection.");

    expect(probeAuditRows())->toBe(0);
});

it('is not satisfied by a transaction on another connection', function (): void {
    $other = DB::connection('mysql_locks');
    $other->beginTransaction();

    expect($other->transactionLevel())->toBe(1)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(fn () => recordProbeAudit())
        ->toThrow(AuditOutsideTransaction::class);

    $other->rollBack();

    expect(probeAuditRows())->toBe(0);
});

it('keeps the record when the caller commits', function (): void {
    DB::transaction(fn () => recordProbeAudit());

    expect(probeAuditRows())->toBe(1);
});

it('loses the record when the caller rolls back', function (): void {
    expect(fn () => DB::transaction(function (): void {
        recordProbeAudit();

        throw new RuntimeException('The caller gave up.');
    }))->toThrow(RuntimeException::class, 'The caller gave up.');

    expect(probeAuditRows())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('loses the record when its savepoint rolls back, while the outer commit keeps the rest', function (): void {
    DB::transaction(function (): void {
        recordProbeAudit(1);

        try {
            DB::transaction(function (): void {
                recordProbeAudit(2);

                throw new RuntimeException('The inner step failed.');
            });
        } catch (RuntimeException) {
        }
    });

    expect(DB::table('audit_log')->where('subject_type', AUDIT_PROBE_SUBJECT)->pluck('subject_id')->all())->toBe([1]);
});

it('never commits the caller\'s transaction', function (): void {
    DB::beginTransaction();
    recordProbeAudit();
    $level = DB::transactionLevel();
    DB::rollBack();

    // Had record() committed, its row would have survived the rollback.
    expect($level)->toBe(1)
        ->and(probeAuditRows())->toBe(0);
});

it('never rolls back the caller\'s transaction when its insert fails', function (): void {
    DB::beginTransaction();
    $user = probeBusinessChange();
    AuditInsertFailure::injectOnce();

    expect(fn () => recordProbeAudit($user))->toThrow(QueryException::class, AuditInsertFailure::MESSAGE);

    $level = DB::transactionLevel();
    DB::commit();

    // The caller chose to commit after the failure: its own change survives,
    // so the recorder ended nothing.
    expect($level)->toBe(1)
        ->and(probeUsers())->toBe(1)
        ->and(probeAuditRows())->toBe(0);
});

it('commits a business change and its record together', function (): void {
    $user = DB::transaction(function (): int {
        $user = probeBusinessChange();
        recordProbeAudit($user);

        return $user;
    });

    expect(probeUsers())->toBe(1)
        ->and(DB::table('audit_log')->where('subject_type', AUDIT_PROBE_SUBJECT)->value('subject_id'))->toBe($user);
});

it('rolls back the business change when the audit insert fails', function (): void {
    $failure = AuditInsertFailure::injectOnce();

    try {
        DB::transaction(function (): void {
            recordProbeAudit(probeBusinessChange());
        });
        $caught = null;
    } catch (Throwable $caught) {
    }

    expect($failure->thrown)->toBeInstanceOf(QueryException::class)
        ->and($caught)->toBe($failure->thrown)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(probeUsers())->toBe(0)
        ->and(probeAuditRows())->toBe(0);
});

it('rolls back the record when the business change fails after it', function (): void {
    expect(fn () => DB::transaction(function (): void {
        recordProbeAudit(probeBusinessChange());

        // A business write the database refuses, after the record was written.
        DB::table('users')->insert(['name' => 'No Email', 'email' => null, 'password' => 'x']);
    }))->toThrow(QueryException::class);

    expect(probeUsers())->toBe(0)
        ->and(probeAuditRows())->toBe(0);
});
