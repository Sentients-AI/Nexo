<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PlatformRole;
use App\Modules\Identity\Domain\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * #33, ADR-0011 Decision 3: users.platform_role. The database enforces the
 * role values on raw writes; nothing in the application writes the column
 * yet (GrantPlatformRole is #36).
 */

/**
 * @return array{0: int, 1: string} The MySQL error code and message.
 */
function userWriteErrorOf(Closure $write): array
{
    try {
        $write();
    } catch (QueryException $exception) {
        return [(int) $exception->errorInfo[1], $exception->getMessage()];
    }

    throw new RuntimeException('The write was expected to fail, but the database accepted it.');
}

function storedUserId(): int
{
    return DB::table('users')->insertGetId([
        'name' => 'Ada Lovelace',
        'email' => 'ada-'.bin2hex(random_bytes(4)).'@example.com',
        'password' => 'not-a-real-hash',
    ]);
}

function writePlatformRole(int $id, ?string $role): void
{
    DB::table('users')->where('id', $id)->update(['platform_role' => $role]);
}

it('has a nullable, binary, NO PAD platform_role column and the named CHECK constraint', function (): void {
    $column = DB::selectOne(
        'SELECT data_type AS data_type, character_maximum_length AS character_maximum_length,
                is_nullable AS is_nullable, column_default AS column_default, collation_name AS collation_name
           FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        ['users', 'platform_role'],
    );
    $check = DB::scalar(
        'SELECT check_clause FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = ?',
        ['users_platform_role_check'],
    );

    expect($column?->data_type)->toBe('varchar')
        ->and((int) $column?->character_maximum_length)->toBe(32)
        ->and($column?->is_nullable)->toBe('YES')
        ->and($column?->column_default)->toBeNull()
        ->and($column?->collation_name)->toBe('utf8mb4_0900_bin')
        ->and($check)->toBeString()
        ->and((string) $check)->toContain('admin', 'operations', 'finance', 'support');
});

it('stores NULL for a user created without a role: there is no default role', function (): void {
    $raw = storedUserId();
    $viaFactory = User::factory()->create();

    expect(DB::table('users')->whereIn('id', [$raw, $viaFactory->getKey()])->pluck('platform_role')->all())->toBe([null, null]);
});

it('accepts each approved role and NULL', function (PlatformRole $role): void {
    $id = storedUserId();

    writePlatformRole($id, $role->value);
    expect(DB::table('users')->where('id', $id)->value('platform_role'))->toBe($role->value);

    writePlatformRole($id, null);
    expect(DB::table('users')->where('id', $id)->value('platform_role'))->toBeNull();
})->with(PlatformRole::cases());

it('rejects a value outside the approved roles', function (string $value): void {
    $id = storedUserId();

    [$code, $message] = userWriteErrorOf(fn () => writePlatformRole($id, $value));

    expect($code)->toBe(3819)
        ->and($message)->toContain('users_platform_role_check')
        ->and(DB::table('users')->where('id', $id)->value('platform_role'))->toBeNull();
})->with([
    'unknown role' => 'owner',
    'empty string' => '',
    'upper case' => 'Admin',
    'all capitals' => 'ADMIN',
    'trailing space' => 'admin ',
    'leading space' => ' admin',
    'accented' => 'àdmin',
]);

it('has exactly the enum values the constraint accepts', function (): void {
    expect(array_map(fn (PlatformRole $role): string => $role->value, PlatformRole::cases()))
        ->toBe(['admin', 'operations', 'finance', 'support']);
});

it('cannot be mass-assigned', function (): void {
    $user = new User(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-password', 'platform_role' => 'admin']);

    expect($user->getAttributes())->not->toHaveKey('platform_role')
        ->and($user->isFillable('platform_role'))->toBeFalse();
});

it('rolls back without losing users, and re-runs leaving them non-staff', function (): void {
    // DDL commits the test transaction implicitly; RefreshDatabase notices
    // and migrates afresh before the next test.
    $migration = require database_path('migrations/2026_11_24_000000_add_platform_role_to_users_table.php');
    $staff = storedUserId();
    $customer = storedUserId();
    writePlatformRole($staff, 'finance');

    $migration->down();

    expect(Schema::hasColumn('users', 'platform_role'))->toBeFalse()
        ->and(DB::scalar(
            'SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = ?',
            ['users_platform_role_check'],
        ))->toBe(0)
        ->and(DB::table('users')->whereIn('id', [$staff, $customer])->count())->toBe(2);

    $migration->up();

    expect(DB::table('users')->whereIn('id', [$staff, $customer])->pluck('platform_role')->all())->toBe([null, null]);

    [$code] = userWriteErrorOf(fn () => writePlatformRole($customer, 'owner'));
    expect($code)->toBe(3819);
});
