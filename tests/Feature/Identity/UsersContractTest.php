<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\LockedUser;
use App\Modules\Identity\Contracts\PlatformRole;
use App\Modules\Identity\Contracts\UserNotFound;
use App\Modules\Identity\Contracts\Users;
use App\Modules\Identity\Infrastructure\DatabaseUsers;
use Illuminate\Support\Facades\DB;

/*
 * #33, ADR-0011 Decision 11: Identity's Users contract. These run inside
 * RefreshDatabase's transaction. The transaction protocol (no transaction,
 * commit, rollback, other connections) is proven in
 * tests/Concurrency/UserRowLockTest.php, which runs without one.
 */

function identityUserWithRole(?string $role): int
{
    return DB::table('users')->insertGetId([
        'name' => 'Ada Lovelace',
        'email' => 'ada-'.bin2hex(random_bytes(4)).'@example.com',
        'password' => 'not-a-real-hash',
        'platform_role' => $role,
    ]);
}

it('is bound by IdentityServiceProvider to Identity\'s database implementation, once', function (): void {
    expect(app(Users::class))->toBeInstanceOf(DatabaseUsers::class)
        ->and(app(Users::class))->toBe(app(Users::class));
});

it('says whether a user exists', function (): void {
    $id = identityUserWithRole(null);

    expect(app(Users::class)->exists($id))->toBeTrue()
        ->and(app(Users::class)->exists($id + 1000))->toBeFalse();
});

it('reads a platform role, and null for a user who is not staff', function (): void {
    $staff = identityUserWithRole('operations');
    $customer = identityUserWithRole(null);

    expect(app(Users::class)->platformRole($staff))->toBe(PlatformRole::Operations)
        ->and(app(Users::class)->platformRole($customer))->toBeNull();
});

it('refuses to answer for a missing user rather than calling them non-staff', function (string $method): void {
    $missing = identityUserWithRole(null) + 1000;

    expect(fn () => app(Users::class)->{$method}($missing))
        ->toThrow(UserNotFound::class, "User {$missing} does not exist.");
})->with(['platformRole', 'lockForUpdate']);

it('returns the locked row\'s id and role inside the caller\'s transaction, leaving it open', function (): void {
    $id = identityUserWithRole('finance');
    $level = DB::transactionLevel();

    $locked = app(Users::class)->lockForUpdate($id);

    expect($locked)->toEqual(new LockedUser($id, PlatformRole::Finance))
        ->and($locked->isStaff())->toBeTrue()
        ->and(DB::transactionLevel())->toBe($level);
});

it('leaves the caller\'s transaction open when the user is missing', function (): void {
    $level = DB::transactionLevel();

    expect(fn () => app(Users::class)->lockForUpdate(999_999))->toThrow(UserNotFound::class)
        ->and(DB::transactionLevel())->toBe($level);
});
