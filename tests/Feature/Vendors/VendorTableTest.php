<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The vendors table enforces the Vendor persistence invariants itself
 * (vendor-domain-design.md §6, §7, §9). These tests write through the query
 * builder, not the model, so each one proves a database constraint.
 */

/**
 * Run $write and return the MySQL error it fails with: [code, message].
 *
 * @return array{0: int, 1: string}
 */
function mysqlErrorOf(Closure $write): array
{
    try {
        $write();
    } catch (QueryException $exception) {
        return [(int) $exception->errorInfo[1], $exception->getMessage()];
    }

    throw new RuntimeException('The write was expected to fail, but the database accepted it.');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertVendor(array $attributes = []): int
{
    return DB::table('vendors')->insertGetId($attributes + [
        'slug' => 'acme-supplies',
        'name' => 'Acme Supplies',
    ]);
}

it('has only the approved columns', function (): void {
    $columns = collect(DB::select(
        'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default`, extra AS extra, collation_name AS `collation`
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ?
         ORDER BY ordinal_position',
        ['vendors'],
    ))->mapWithKeys(fn (object $column): array => [$column->name => [$column->type, $column->nullable, $column->default, $column->extra, $column->collation]]);

    expect($columns->all())->toBe([
        'id' => ['bigint unsigned', 'NO', null, 'auto_increment', null],
        'slug' => ['varchar(255)', 'NO', null, '', 'utf8mb4_0900_ai_ci'],
        'name' => ['varchar(255)', 'NO', null, '', 'utf8mb4_0900_ai_ci'],
        'status' => ['varchar(32)', 'NO', 'pending', '', 'utf8mb4_0900_bin'],
        'created_at' => ['timestamp(6)', 'YES', null, '', null],
        'updated_at' => ['timestamp(6)', 'YES', null, '', null],
    ]);
});

it('has a primary key, a unique slug, a status check and no foreign key', function (): void {
    $constraints = collect(DB::select(
        'SELECT constraint_name AS name, constraint_type AS type
         FROM information_schema.table_constraints
         WHERE table_schema = DATABASE() AND table_name = ?',
        ['vendors'],
    ))->mapWithKeys(fn (object $constraint): array => [$constraint->name => $constraint->type])->sortKeys();

    expect($constraints->all())->toBe([
        'PRIMARY' => 'PRIMARY KEY',
        'vendors_slug_unique' => 'UNIQUE',
        'vendors_status_check' => 'CHECK',
    ]);
});

it('indexes status for lifecycle lookups', function (): void {
    expect(Schema::getIndexListing('vendors'))->toContain('vendors_status_index');
});

it('generates vendor ids in the database', function (): void {
    $first = insertVendor(['slug' => 'first-vendor']);
    $second = insertVendor(['slug' => 'second-vendor']);

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBeGreaterThan($first);
});

it('rejects a duplicate slug', function (): void {
    insertVendor(['slug' => 'acme-supplies']);

    [$code, $message] = mysqlErrorOf(fn () => insertVendor(['slug' => 'acme-supplies', 'name' => 'Another Acme']));

    expect($code)->toBe(1062)
        ->and($message)->toContain('vendors_slug_unique');
});

it('treats slugs that differ only in case or accents as duplicates, under the slug collation', function (string $collidingSlug): void {
    // The collision follows from the column's collation, so pin it: a change
    // of collation must show up here, not as a silent change in uniqueness.
    $collation = DB::scalar(
        'SELECT collation_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        ['vendors', 'slug'],
    );
    expect($collation)->toBe('utf8mb4_0900_ai_ci');

    insertVendor(['slug' => 'acme-cafe']);

    [$code, $message] = mysqlErrorOf(fn () => insertVendor(['slug' => $collidingSlug]));

    expect($code)->toBe(1062)
        ->and($message)->toContain('vendors_slug_unique');
})->with([
    'upper case' => 'ACME-CAFE',
    'mixed case' => 'Acme-Cafe',
    'accented' => 'acme-café',
]);

it('allows two vendors with the same name', function (): void {
    insertVendor(['slug' => 'acme-kl', 'name' => 'Acme']);
    insertVendor(['slug' => 'acme-penang', 'name' => 'Acme']);

    expect(DB::table('vendors')->where('name', 'Acme')->count())->toBe(2);
});

it('defaults a missing status to pending in the database', function (): void {
    $id = insertVendor();

    expect(DB::table('vendors')->where('id', $id)->value('status'))->toBe('pending');
});

it('accepts each lifecycle status', function (string $status): void {
    $id = insertVendor(['status' => $status]);

    expect(DB::table('vendors')->where('id', $id)->value('status'))->toBe($status);
})->with(['pending', 'approved', 'suspended']);

it('rejects a status outside the lifecycle', function (string $status): void {
    [$code, $message] = mysqlErrorOf(fn () => insertVendor(['status' => $status]));

    expect($code)->toBe(3819)
        ->and($message)->toContain('vendors_status_check');
})->with([
    'unknown state' => 'active',
    'rejected application' => 'rejected',
    'empty string' => '',
    'different case' => 'PENDING',
    'accented' => 'pendíng',
    'trailing space' => 'pending ',
]);

it('rejects an update to a status outside the lifecycle', function (): void {
    $id = insertVendor();

    [$code] = mysqlErrorOf(fn () => DB::table('vendors')->where('id', $id)->update(['status' => 'deleted']));

    expect($code)->toBe(3819)
        ->and(DB::table('vendors')->where('id', $id)->value('status'))->toBe('pending');
});

it('rejects null in a required column', function (string $column): void {
    [$code, $message] = mysqlErrorOf(fn () => insertVendor([$column => null]));

    expect($code)->toBe(1048)
        ->and($message)->toContain("Column '{$column}' cannot be null");
})->with(['slug', 'name', 'status']);

it('rejects a vendor without a slug or a name', function (string $column): void {
    $attributes = ['slug' => 'acme-supplies', 'name' => 'Acme Supplies'];
    unset($attributes[$column]);

    [$code, $message] = mysqlErrorOf(fn () => DB::table('vendors')->insert($attributes));

    expect($code)->toBe(1364)
        ->and($message)->toContain("Field '{$column}' doesn't have a default value");
})->with(['slug', 'name']);

it('rolls back and re-runs cleanly', function (): void {
    // DDL commits the test transaction implicitly; RefreshDatabase notices
    // and migrates afresh before the next test.
    $migration = require database_path('migrations/2026_11_22_000000_create_vendors_table.php');

    $migration->down();
    expect(Schema::hasTable('vendors'))->toBeFalse()
        ->and(DB::scalar(
            'SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = ?',
            ['vendors_status_check'],
        ))->toBe(0);

    $migration->up();
    expect(Schema::hasTable('vendors'))->toBeTrue();

    [$code] = mysqlErrorOf(fn () => insertVendor(['status' => 'active']));
    expect($code)->toBe(3819);
});
