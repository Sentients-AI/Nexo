<?php

declare(strict_types=1);

use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Audit\AuditInsertFailure;

/*
 * #34, ADR-0023 Decisions 1 and 4: AuditRecorder::record() inside a
 * transaction. These run inside RefreshDatabase's transaction, so every
 * nested DB::transaction() here is a savepoint. The protocol at the top
 * level (no transaction, a real commit or rollback, another connection's
 * transaction) is proven in tests/Concurrency/AuditRecorderTransactionTest.php,
 * which runs without one.
 */

function recordConsoleAudit(string $action = 'vendor.created', int $subjectId = 1): void
{
    app(AuditRecorder::class)->record(Actor::console('vendors:create'), $action, 'vendor', $subjectId, null, ['status' => 'pending']);
}

it('writes exactly one row with every field it is given', function (): void {
    Carbon::setTestNow('2026-11-25 10:11:12.345678');

    app(AuditRecorder::class)->record(
        Actor::user(7, impersonatorUserId: 3, ip: '2001:db8::1'),
        'vendor.approved',
        'vendor',
        42,
        ['status' => 'pending', 'name' => 'Café Acme'],
        ['status' => 'approved', 'name' => 'Café Acme'],
    );

    $rows = DB::table('audit_log')->get();

    expect($rows)->toHaveCount(1);

    $row = (array) $rows->first();

    expect($row['id'])->toBeInt()->toBeGreaterThan(0);
    unset($row['id']);

    expect(json_decode($row['before'], true, flags: JSON_THROW_ON_ERROR))->toBe(['name' => 'Café Acme', 'status' => 'pending'])
        ->and(json_decode($row['after'], true, flags: JSON_THROW_ON_ERROR))->toBe(['name' => 'Café Acme', 'status' => 'approved']);
    unset($row['before'], $row['after']);

    expect($row)->toBe([
        'occurred_at' => '2026-11-25 10:11:12.345678',
        'actor_type' => 'user',
        'actor_user_id' => 7,
        'impersonator_user_id' => 3,
        'actor_label' => null,
        'action' => 'vendor.approved',
        'subject_type' => 'vendor',
        'subject_id' => 42,
        'ip' => '2001:db8::1',
    ]);
});

it('records a console actor as provenance, with no user', function (): void {
    recordConsoleAudit();

    $row = DB::table('audit_log')->first(['actor_type', 'actor_user_id', 'impersonator_user_id', 'actor_label', 'ip', 'before', 'after']);

    expect((array) $row)->toBe([
        'actor_type' => 'console',
        'actor_user_id' => null,
        'impersonator_user_id' => null,
        'actor_label' => 'vendors:create',
        'ip' => null,
        'before' => null,
        'after' => '{"status": "pending"}',
    ]);
});

it('records a system actor with its job as the label', function (): void {
    app(AuditRecorder::class)->record(Actor::system('App\Jobs\ExpireCarts'), 'cart.expired', 'cart', 5, ['state' => 'open'], null);

    $row = DB::table('audit_log')->first(['actor_type', 'actor_user_id', 'actor_label', 'after']);

    expect((array) $row)->toBe(['actor_type' => 'system', 'actor_user_id' => null, 'actor_label' => 'App\Jobs\ExpireCarts', 'after' => null]);
});

it('stores an empty snapshot as a JSON object, and keeps values exactly', function (): void {
    app(AuditRecorder::class)->record(Actor::console('vendors:create'), 'vendor.created', 'vendor', 1, [], [
        'rate' => 1.0,
        'tags' => ['a', 'b'],
        'note' => null,
        'flag' => false,
    ]);

    $row = DB::selectOne('SELECT `before`, JSON_TYPE(`before`) AS before_type, `after` FROM audit_log');

    expect($row->before)->toBe('{}')
        ->and($row->before_type)->toBe('OBJECT')
        ->and(json_decode($row->after, true, flags: JSON_THROW_ON_ERROR))->toBe(['flag' => false, 'note' => null, 'rate' => 1.0, 'tags' => ['a', 'b']]);
});

it('writes one row per call', function (): void {
    recordConsoleAudit('vendor.created', 1);
    recordConsoleAudit('vendor.created', 1);
    recordConsoleAudit('vendor.approved', 1);

    expect(DB::table('audit_log')->count())->toBe(3);
});

it('keeps the record when its savepoint is released, for the outer transaction to decide', function (): void {
    DB::transaction(fn () => recordConsoleAudit());

    expect(DB::table('audit_log')->count())->toBe(1);
});

it('loses the record when its savepoint is rolled back, and leaves the outer transaction usable', function (): void {
    $level = DB::transactionLevel();

    expect(fn () => DB::transaction(function (): void {
        recordConsoleAudit();

        throw new RuntimeException('The business change failed.');
    }))->toThrow(RuntimeException::class, 'The business change failed.');

    expect(DB::table('audit_log')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe($level);

    recordConsoleAudit('vendor.approved');

    expect(DB::table('audit_log')->pluck('action')->all())->toBe(['vendor.approved']);
});

it('begins, commits and rolls back no transaction of its own', function (): void {
    $level = DB::transactionLevel();
    $events = [];
    Event::listen([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class], function (object $event) use (&$events): void {
        $events[] = $event::class;
    });

    recordConsoleAudit();

    expect($events)->toBe([])
        ->and(DB::transactionLevel())->toBe($level);
});

it('lets an injected insert failure reach the caller, writing nothing and ending no transaction', function (): void {
    $failure = AuditInsertFailure::injectOnce();
    $level = DB::transactionLevel();
    $events = [];
    Event::listen([TransactionCommitted::class, TransactionRolledBack::class], function (object $event) use (&$events): void {
        $events[] = $event::class;
    });

    try {
        recordConsoleAudit();
        $caught = null;
    } catch (Throwable $caught) {
    }

    expect($failure->thrown)->toBeInstanceOf(QueryException::class)
        ->and($caught)->toBe($failure->thrown)
        ->and($events)->toBe([])
        ->and(DB::transactionLevel())->toBe($level)
        ->and(DB::table('audit_log')->count())->toBe(0);

    // Injected once: the next record is written.
    recordConsoleAudit();
    expect(DB::table('audit_log')->count())->toBe(1);
});

it('lets a database rejection reach the caller', function (): void {
    // action is VARCHAR(100); the strict SQL mode refuses a longer one.
    expect(fn () => recordConsoleAudit(str_repeat('a', 101)))
        ->toThrow(QueryException::class, "Data too long for column 'action'");

    expect(DB::table('audit_log')->count())->toBe(0);
});

it('rejects a malformed action, subject type or subject id before writing', function (Closure $record, string $message): void {
    expect($record)->toThrow(InvalidArgumentException::class, $message);
    expect(DB::table('audit_log')->count())->toBe(0);
})->with([
    'empty action' => [fn () => recordConsoleAudit(''), 'An audit action must be non-empty'],
    'padded action' => [fn () => recordConsoleAudit(' vendor.created'), 'An audit action must be non-empty'],
    'empty subject type' => [fn () => app(AuditRecorder::class)->record(Actor::console('vendors:create'), 'vendor.created', '', 1, null, null), 'An audit subject type must be non-empty'],
    'zero subject id' => [fn () => recordConsoleAudit('vendor.created', 0), 'An audit subject id must be a positive id, got 0.'],
    'negative subject id' => [fn () => recordConsoleAudit('vendor.created', -3), 'An audit subject id must be a positive id, got -3.'],
]);

it('rejects a snapshot that is not JSON-encodable before writing', function (): void {
    expect(fn () => app(AuditRecorder::class)->record(Actor::console('vendors:create'), 'vendor.created', 'vendor', 1, null, ['name' => "\xB1\x31"]))
        ->toThrow(JsonException::class);

    expect(DB::table('audit_log')->count())->toBe(0);
});

it('offers record() and nothing else', function (): void {
    $methods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(AuditRecorder::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toBe(['__construct', 'record']);
});
