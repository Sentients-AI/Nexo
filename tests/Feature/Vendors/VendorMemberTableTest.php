<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\User;
use App\Modules\Vendors\Domain\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function insertMember(int $vendor, int $user, array $attributes = []): int
{
    return DB::table('vendor_members')->insertGetId($attributes + [
        'vendor_id' => $vendor,
        'user_id' => $user,
        'role' => 'manager',
    ]);
}

function expectMemberConstraint(Closure $write, int $code, string $constraint): void
{
    try {
        $write();
        test()->fail("Expected MySQL error {$code} on {$constraint}");
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($code)
            ->and($exception->getMessage())->toContain($constraint);
    }
}

it('allows each approved role and defaults to active', function (string $role): void {
    $id = insertMember(Vendor::factory()->create()->id, User::factory()->create()->id, ['role' => $role]);
    $row = DB::table('vendor_members')->find($id);

    expect($row->role)->toBe($role)
        ->and($row->status)->toBe('active')
        ->and($row->active_marker)->toBe(1)
        ->and($row->owner_marker)->toBe($role === 'owner' ? 1 : null);
})->with(['owner', 'manager', 'fulfilment', 'finance', 'support']);

it('rejects invalid roles and statuses using exact comparisons', function (string $field, string $value): void {
    $vendor = Vendor::factory()->create()->id;
    $user = User::factory()->create()->id;

    if ($field === 'status') {
        // Avoid an overlapping timestamp mismatch for the invalid status.
        expectMemberConstraint(fn () => insertMember($vendor, $user, [$field => $value]), 3819, 'vendor_members_status_check');
    } else {
        expectMemberConstraint(fn () => insertMember($vendor, $user, [$field => $value]), 3819, 'vendor_members_role_check');
    }
})->with([
    ['role', 'Owner'], ['role', 'owner '], ['role', 'ównér'], ['role', 'customer'],
    ['status', 'Active'], ['status', 'active '], ['status', 'áctivé'], ['status', 'pending'],
]);

it('requires the deactivation timestamp precisely when deactivated', function (array $attributes): void {
    $vendor = Vendor::factory()->create()->id;
    $user = User::factory()->create()->id;

    expectMemberConstraint(fn () => insertMember($vendor, $user, $attributes), 3819, 'vendor_members_deactivation_check');
})->with([
    [['status' => 'deactivated']],
    [['deactivated_at' => '2026-01-01 01:02:03.123456']],
]);

it('allows only one active membership per user while retaining history', function (): void {
    $first = Vendor::factory()->create()->id;
    $second = Vendor::factory()->create()->id;
    $user = User::factory()->create()->id;
    $id = insertMember($first, $user);

    expectMemberConstraint(fn () => insertMember($second, $user), 1062, 'vendor_members_one_active_per_user');
    $date = '2026-01-01 01:02:03.123456';
    DB::table('vendor_members')->where('id', $id)->update(['status' => 'deactivated', 'deactivated_at' => $date]);
    insertMember($first, $user, ['status' => 'deactivated', 'deactivated_at' => $date]);
    insertMember($second, $user);

    expect(DB::table('vendor_members')->where('user_id', $user)->count())->toBe(3)
        ->and(DB::table('vendor_members')->find($id)->active_marker)->toBeNull()
        ->and(DB::table('vendor_members')->find($id)->deactivated_at)->toBe($date);
});

it('allows only one active owner and recomputes the owner marker on role change', function (): void {
    $vendor = Vendor::factory()->create()->id;
    $old = User::factory()->create()->id;
    $new = User::factory()->create()->id;
    $id = insertMember($vendor, $old, ['role' => 'owner']);

    expectMemberConstraint(fn () => insertMember($vendor, $new, ['role' => 'owner']), 1062, 'vendor_members_one_active_owner');
    DB::table('vendor_members')->where('id', $id)->update(['role' => 'manager']);
    $next = insertMember($vendor, $new, ['role' => 'owner']);

    expect(DB::table('vendor_members')->find($id)->owner_marker)->toBeNull()
        ->and(DB::table('vendor_members')->find($next)->owner_marker)->toBe(1);
});

it('rejects direct writes to stored markers', function (string $marker): void {
    $vendor = Vendor::factory()->create()->id;
    $user = User::factory()->create()->id;
    expectMemberConstraint(fn () => insertMember($vendor, $user, [$marker => 1]), 3105, $marker);
})->with(['active_marker', 'owner_marker']);

it('restricts foreign references and parent mutation', function (): void {
    $vendor = Vendor::factory()->create()->id;
    $user = User::factory()->create()->id;
    expectMemberConstraint(fn () => insertMember(999999, $user), 1452, 'vendor_members_vendor_id_foreign');
    expectMemberConstraint(fn () => insertMember($vendor, 999999), 1452, 'vendor_members_user_id_foreign');

    insertMember($vendor, $user);
    expectMemberConstraint(fn () => DB::table('vendors')->where('id', $vendor)->delete(), 1451, 'vendor_members_vendor_id_foreign');
    expectMemberConstraint(fn () => DB::table('users')->where('id', $user)->delete(), 1451, 'vendor_members_user_id_foreign');
    expectMemberConstraint(fn () => DB::table('vendors')->where('id', $vendor)->update(['id' => 999999]), 1451, 'vendor_members_vendor_id_foreign');
    expectMemberConstraint(fn () => DB::table('users')->where('id', $user)->update(['id' => 999999]), 1451, 'vendor_members_user_id_foreign');
});

it('exposes the expected membership indexes, constraints and stored expressions in MySQL metadata', function (): void {
    $metadata = static fn (string $table) => DB::table("information_schema.{$table}")
        ->where($table === 'REFERENTIAL_CONSTRAINTS' ? 'CONSTRAINT_SCHEMA' : 'TABLE_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TABLE_NAME', 'vendor_members');
    $indexes = $metadata('STATISTICS')->orderBy('SEQ_IN_INDEX')->get();
    $columns = $metadata('COLUMNS')->get()->keyBy('COLUMN_NAME');

    expect($indexes->groupBy('INDEX_NAME')->map->pluck('COLUMN_NAME')->sortKeys()->toArray())->toBe([
        'PRIMARY' => ['id'],
        'vendor_members_one_active_owner' => ['vendor_id', 'owner_marker'],
        'vendor_members_one_active_per_user' => ['user_id', 'active_marker'],
        'vendor_members_vendor_status_index' => ['vendor_id', 'status'],
    ])->and($indexes->where('NON_UNIQUE', 0)->pluck('INDEX_NAME')->unique()->sort()->values()->all())->toBe([
        'PRIMARY', 'vendor_members_one_active_owner', 'vendor_members_one_active_per_user',
    ])->and($metadata('TABLE_CONSTRAINTS')->where('CONSTRAINT_TYPE', 'CHECK')->orderBy('CONSTRAINT_NAME')->pluck('CONSTRAINT_NAME')->all())->toBe([
        'vendor_members_deactivation_check', 'vendor_members_role_check', 'vendor_members_status_check',
    ])->and($metadata('REFERENTIAL_CONSTRAINTS')->get()->mapWithKeys(
        fn (object $row): array => [$row->CONSTRAINT_NAME => [$row->REFERENCED_TABLE_NAME, $row->UPDATE_RULE, $row->DELETE_RULE]],
    )->sortKeys()->all())->toBe([
        'vendor_members_user_id_foreign' => ['users', 'RESTRICT', 'RESTRICT'],
        'vendor_members_vendor_id_foreign' => ['vendors', 'RESTRICT', 'RESTRICT'],
    ]);

    foreach (['role', 'status'] as $field) {
        expect($columns[$field]->COLLATION_NAME)->toBe('utf8mb4_0900_bin');
    }
    foreach (['created_at', 'updated_at', 'deactivated_at'] as $field) {
        expect($columns[$field]->DATETIME_PRECISION)->toBe(6);
    }
    expect($columns['active_marker']->EXTRA)->toBe('STORED GENERATED')
        ->and($columns['active_marker']->GENERATION_EXPRESSION)->toContain('`status`', 'active')
        ->and($columns['owner_marker']->EXTRA)->toBe('STORED GENERATED')
        ->and($columns['owner_marker']->GENERATION_EXPRESSION)->toContain('`status`', 'active', '`role`', 'owner');
});

it('rolls back and reapplies the membership migration', function (): void {
    // As with the parent table, DDL commits implicitly in MySQL.
    $migration = require database_path('migrations/2026_11_26_000000_create_vendor_members_table.php');
    $migration->down();
    expect(Schema::hasTable('vendor_members'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('vendor_members'))->toBeTrue();
});
