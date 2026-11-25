<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * #34, ADR-0023 Decision 3: the audit_log table enforces the actor shapes
 * itself. These tests write through the query builder, never through
 * AuditRecorder or Actor, so each one proves a database rule.
 */

/**
 * Run $write and return the MySQL error it fails with: [code, message].
 *
 * @return array{0: int, 1: string}
 */
function auditLogErrorOf(Closure $write): array
{
    try {
        $write();
    } catch (QueryException $exception) {
        return [(int) $exception->errorInfo[1], $exception->getMessage()];
    }

    throw new RuntimeException('The write was expected to fail, but the database accepted it.');
}

/**
 * A valid console row, with $attributes overriding it.
 *
 * @param  array<string, mixed>  $attributes
 */
function insertAuditLogRow(array $attributes = []): int
{
    return DB::table('audit_log')->insertGetId($attributes + [
        'occurred_at' => '2026-11-25 10:11:12.123456',
        'actor_type' => 'console',
        'actor_user_id' => null,
        'impersonator_user_id' => null,
        'actor_label' => 'vendors:create',
        'action' => 'vendor.created',
        'subject_type' => 'vendor',
        'subject_id' => 1,
    ]);
}

it('has only the approved columns', function (): void {
    $columns = collect(DB::select(
        'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default`, extra AS extra, collation_name AS `collation`
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ?
         ORDER BY ordinal_position',
        ['audit_log'],
    ))->mapWithKeys(fn (object $column): array => [$column->name => [$column->type, $column->nullable, $column->default, $column->extra, $column->collation]]);

    // No updated_at and no created_at: occurred_at is the only timestamp.
    expect($columns->all())->toBe([
        'id' => ['bigint unsigned', 'NO', null, 'auto_increment', null],
        'occurred_at' => ['timestamp(6)', 'NO', null, '', null],
        'actor_type' => ['varchar(16)', 'NO', null, '', 'utf8mb4_0900_bin'],
        'actor_user_id' => ['bigint unsigned', 'YES', null, '', null],
        'impersonator_user_id' => ['bigint unsigned', 'YES', null, '', null],
        'actor_label' => ['varchar(191)', 'YES', null, '', 'utf8mb4_0900_ai_ci'],
        'action' => ['varchar(100)', 'NO', null, '', 'utf8mb4_0900_bin'],
        'subject_type' => ['varchar(64)', 'NO', null, '', 'utf8mb4_0900_bin'],
        'subject_id' => ['bigint unsigned', 'NO', null, '', null],
        'before' => ['json', 'YES', null, '', null],
        'after' => ['json', 'YES', null, '', null],
        'ip' => ['varchar(45)', 'YES', null, '', 'utf8mb4_0900_ai_ci'],
    ]);
});

it('has a primary key and the three named checks, and no foreign key', function (): void {
    $constraints = collect(DB::select(
        'SELECT constraint_name AS name, constraint_type AS type
         FROM information_schema.table_constraints
         WHERE table_schema = DATABASE() AND table_name = ?',
        ['audit_log'],
    ))->mapWithKeys(fn (object $constraint): array => [$constraint->name => $constraint->type])->sortKeys();

    expect($constraints->all())->toBe([
        'PRIMARY' => 'PRIMARY KEY',
        'audit_log_actor_type_check' => 'CHECK',
        'audit_log_actor_user_check' => 'CHECK',
        'audit_log_impersonator_check' => 'CHECK',
    ]);
});

it('defines each check exactly as ADR-0023 states it', function (): void {
    $clauses = collect(DB::select(
        'SELECT cc.constraint_name AS name, cc.check_clause AS clause, tc.enforced AS enforced
         FROM information_schema.check_constraints cc
         JOIN information_schema.table_constraints tc
           ON tc.constraint_schema = cc.constraint_schema AND tc.constraint_name = cc.constraint_name
         WHERE cc.constraint_schema = DATABASE() AND tc.table_name = ?',
        ['audit_log'],
    ))->mapWithKeys(fn (object $check): array => [
        // MySQL renders the literals with escaped quotes; unescape them to compare.
        $check->name => [str_replace("\'", "'", $check->clause), $check->enforced],
    ])->sortKeys();

    // MySQL's own rendering of the migration's clauses.
    expect($clauses->all())->toBe([
        'audit_log_actor_type_check' => ["(`actor_type` in (_utf8mb4'user',_utf8mb4'system',_utf8mb4'console'))", 'YES'],
        'audit_log_actor_user_check' => ["((`actor_type` = _utf8mb4'user') = (`actor_user_id` is not null))", 'YES'],
        'audit_log_impersonator_check' => ["((`impersonator_user_id` is null) or (`actor_type` = _utf8mb4'user'))", 'YES'],
    ]);
});

it('has only the approved indexes', function (): void {
    $indexes = collect(DB::select(
        'SELECT index_name AS name, non_unique AS non_unique, seq_in_index AS seq, column_name AS `column`
         FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ?
         ORDER BY index_name, seq_in_index',
        ['audit_log'],
    ))->groupBy('name')->map(fn ($columns): array => [
        (int) $columns->first()->non_unique,
        $columns->pluck('column')->all(),
    ])->sortKeys();

    expect($indexes->all())->toBe([
        'PRIMARY' => [0, ['id']],
        'audit_log_actor_index' => [1, ['actor_user_id', 'occurred_at']],
        'audit_log_subject_index' => [1, ['subject_type', 'subject_id']],
    ]);
});

it('accepts every actor shape the checks allow', function (array $actor): void {
    $id = insertAuditLogRow($actor);

    $row = DB::table('audit_log')->where('id', $id)->first(['actor_type', 'actor_user_id', 'impersonator_user_id', 'actor_label']);

    expect((array) $row)->toBe($actor + ['actor_label' => 'vendors:create']);
})->with([
    'user' => [['actor_type' => 'user', 'actor_user_id' => 7, 'impersonator_user_id' => null, 'actor_label' => null]],
    'impersonated user' => [['actor_type' => 'user', 'actor_user_id' => 7, 'impersonator_user_id' => 3, 'actor_label' => null]],
    'console' => [['actor_type' => 'console', 'actor_user_id' => null, 'impersonator_user_id' => null, 'actor_label' => 'vendors:create']],
    'system' => [['actor_type' => 'system', 'actor_user_id' => null, 'impersonator_user_id' => null, 'actor_label' => 'App\Jobs\ExpireCarts']],
]);

it('rejects an actor type outside user, system and console', function (string $type): void {
    // No user id, so only the type check can fail.
    [$code, $message] = auditLogErrorOf(fn () => insertAuditLogRow(['actor_type' => $type]));

    expect($code)->toBe(3819)
        ->and($message)->toContain('audit_log_actor_type_check');
})->with([
    'unknown type' => 'admin',
    'empty string' => '',
    'capitalised' => 'Console',
    'upper case' => 'SYSTEM',
    'accented' => 'consolé',
    // utf8mb4_0900_bin is NO PAD: a trailing space is a different value.
    'trailing space' => 'console ',
    'leading space' => ' console',
]);

it('rejects a user actor without a user id, and any other actor with one', function (array $actor): void {
    [$code, $message] = auditLogErrorOf(fn () => insertAuditLogRow($actor));

    expect($code)->toBe(3819)
        ->and($message)->toContain('audit_log_actor_user_check');
})->with([
    'user without a user id' => [['actor_type' => 'user', 'actor_user_id' => null, 'actor_label' => null]],
    'console with a user id' => [['actor_type' => 'console', 'actor_user_id' => 7]],
    'system with a user id' => [['actor_type' => 'system', 'actor_user_id' => 7]],
]);

it('rejects an impersonator on an actor that is not a user', function (string $type): void {
    // No user id, so only the impersonator check can fail.
    [$code, $message] = auditLogErrorOf(fn () => insertAuditLogRow(['actor_type' => $type, 'impersonator_user_id' => 3]));

    expect($code)->toBe(3819)
        ->and($message)->toContain('audit_log_impersonator_check');
})->with(['console', 'system']);

it('checks updates as well as inserts', function (): void {
    $id = insertAuditLogRow();

    [$code, $message] = auditLogErrorOf(fn () => DB::table('audit_log')->where('id', $id)->update(['actor_user_id' => 7]));

    expect($code)->toBe(3819)
        ->and($message)->toContain('audit_log_actor_user_check')
        ->and(DB::table('audit_log')->where('id', $id)->value('actor_user_id'))->toBeNull();
});

it('rejects null in a required column', function (string $column): void {
    [$code, $message] = auditLogErrorOf(fn () => insertAuditLogRow([$column => null]));

    expect($code)->toBe(1048)
        ->and($message)->toContain("Column '{$column}' cannot be null");
})->with(['occurred_at', 'actor_type', 'action', 'subject_type', 'subject_id']);

it('leaves the optional columns null when they are not given', function (): void {
    $id = DB::table('audit_log')->insertGetId([
        'occurred_at' => '2026-11-25 10:11:12.000000',
        'actor_type' => 'system',
        'action' => 'cart.expired',
        'subject_type' => 'cart',
        'subject_id' => 1,
    ]);

    $row = DB::table('audit_log')->where('id', $id)->first(['actor_user_id', 'impersonator_user_id', 'actor_label', 'before', 'after', 'ip']);

    expect((array) $row)->toBe([
        'actor_user_id' => null,
        'impersonator_user_id' => null,
        'actor_label' => null,
        'before' => null,
        'after' => null,
        'ip' => null,
    ]);
});

it('keeps microseconds in occurred_at', function (): void {
    $id = insertAuditLogRow(['occurred_at' => '2026-11-25 10:11:12.000001']);

    expect(DB::table('audit_log')->where('id', $id)->value('occurred_at'))->toBe('2026-11-25 10:11:12.000001');
});

it('stores before and after as JSON documents, distinct from SQL null', function (): void {
    $before = ['status' => 'pending', 'name' => 'Acme', 'nested' => ['tags' => ['a', 'b'], 'count' => 2], 'missing' => null];
    $after = ['status' => 'approved'];

    $id = insertAuditLogRow([
        'before' => json_encode($before, JSON_THROW_ON_ERROR),
        'after' => json_encode($after, JSON_THROW_ON_ERROR),
    ]);
    $jsonNull = insertAuditLogRow(['before' => 'null', 'after' => null]);

    $row = DB::selectOne(
        'SELECT JSON_TYPE(`before`) AS before_type, `before`, `after`, JSON_UNQUOTE(JSON_EXTRACT(`after`, "$.status")) AS after_status
         FROM audit_log WHERE id = ?',
        [$id],
    );
    $nulls = DB::selectOne('SELECT JSON_TYPE(`before`) AS before_type, `after` IS NULL AS after_is_sql_null FROM audit_log WHERE id = ?', [$jsonNull]);

    expect($row->before_type)->toBe('OBJECT')
        ->and(json_decode($row->before, true, flags: JSON_THROW_ON_ERROR))->toEqual($before)
        ->and(json_decode($row->after, true, flags: JSON_THROW_ON_ERROR))->toBe($after)
        ->and($row->after_status)->toBe('approved')
        ->and($nulls->before_type)->toBe('NULL')
        ->and((int) $nulls->after_is_sql_null)->toBe(1);
});

it('rejects a before or after value that is not JSON', function (string $column): void {
    [$code] = auditLogErrorOf(fn () => insertAuditLogRow([$column => '{"status": ']));

    expect($code)->toBe(3140);
})->with(['before', 'after']);

it('rejects values longer than their columns', function (string $column, int $length): void {
    [$code, $message] = auditLogErrorOf(fn () => insertAuditLogRow([$column => str_repeat('a', $length + 1)]));

    expect($code)->toBe(1406)
        ->and($message)->toContain("Data too long for column '{$column}'");
})->with([
    'actor_label' => ['actor_label', 191],
    'action' => ['action', 100],
    'subject_type' => ['subject_type', 64],
    'ip' => ['ip', 45],
]);

it('rolls back and re-runs cleanly', function (): void {
    // DDL commits the test transaction implicitly; RefreshDatabase notices
    // and migrates afresh before the next test.
    $migration = require database_path('migrations/2026_11_25_000000_create_audit_log_table.php');

    $migration->down();
    expect(Schema::hasTable('audit_log'))->toBeFalse()
        ->and(DB::scalar(
            "SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name LIKE 'audit\\_log\\_%'",
        ))->toBe(0);

    $migration->up();
    expect(Schema::hasTable('audit_log'))->toBeTrue();

    [$code, $message] = auditLogErrorOf(fn () => insertAuditLogRow(['actor_type' => 'admin']));
    expect($code)->toBe(3819)
        ->and($message)->toContain('audit_log_actor_type_check');
});
