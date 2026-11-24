<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PlatformRole;
use App\Modules\Identity\Contracts\TransactionRequired;
use App\Modules\Identity\Contracts\UserNotFound;
use App\Modules\Identity\Contracts\Users;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Scenarios\LockUserRow;

/*
 * #33, ADR-0011 Decision 10: the user row lock behind Users::lockForUpdate().
 * Separate worker processes, each with its own connection, show that the lock
 * excludes another transaction, waits rather than reads past it, is released
 * on commit and on rollback, and returns the latest committed values. The
 * single-process tests show the transaction protocol: no lock outside a
 * transaction, and the caller's transaction is never ended by the contract.
 *
 * This suite runs without a wrapping test transaction (DatabaseTruncation),
 * so the fixtures are committed. Each test removes the users it stored, by
 * id, whether it passed or failed; Concurrently::run() returns or throws only
 * after every worker has stopped.
 */

afterEach(function (): void {
    $ids = committedUserIds();

    if ($ids->count() > 0) {
        DB::table('users')->whereIn('id', $ids->getArrayCopy())->delete();
        $ids->exchangeArray([]);
    }
});

/** The ids of the users this test committed. */
function committedUserIds(): ArrayObject
{
    /** @var ArrayObject<int, int> $ids */
    static $ids = new ArrayObject;

    return $ids;
}

function committedUser(?string $role = null): int
{
    $id = DB::table('users')->insertGetId([
        'name' => 'Ada Lovelace',
        'email' => 'ada-'.bin2hex(random_bytes(4)).'@example.com',
        'password' => 'not-a-real-hash',
        'platform_role' => $role,
    ]);

    committedUserIds()->append($id);

    return $id;
}

it('holds the row exclusively until the holder commits, then lets the waiter lock it and read the commit', function (): void {
    $id = committedUser();

    [$holder, $contender] = Concurrently::run(app(), LockUserRow::class, workers: 2, arguments: [
        'user_id' => $id,
        'case' => 'exclusion',
        'end' => 'commit',
    ]);

    expect($holder->get('locked_role'))->toBeNull()
        ->and($holder->get('level_after_end'))->toBe(0)
        // Safety: while held, the row could be neither taken at once nor waited for past the holder.
        ->and($contender->get('nowait_error'))->toBe(3572)
        ->and($contender->get('contract_wait_error'))->toBe(1205)
        // Progress: once released, the waiter locked it and read the committed role.
        ->and($contender->get('role_after_release'))->toBe('finance')
        ->and(DB::table('users')->where('id', $id)->value('platform_role'))->toBe('finance');
})->repeat(3);

it('releases the lock on rollback, discarding the holder\'s write', function (): void {
    $id = committedUser();

    [, $contender] = Concurrently::run(app(), LockUserRow::class, workers: 2, arguments: [
        'user_id' => $id,
        'case' => 'exclusion',
        'end' => 'rollback',
    ]);

    expect($contender->get('nowait_error'))->toBe(3572)
        ->and($contender->get('contract_wait_error'))->toBe(1205)
        ->and($contender->get('role_after_release'))->toBeNull()
        ->and(DB::table('users')->where('id', $id)->value('platform_role'))->toBeNull();
})->repeat(3);

it('reads the latest committed role under the lock, not the transaction\'s older snapshot', function (): void {
    $id = committedUser();

    [, $reader] = Concurrently::run(app(), LockUserRow::class, workers: 2, arguments: [
        'user_id' => $id,
        'case' => 'current-state',
    ]);

    expect($reader->get('snapshot_role'))->toBeNull()
        ->and($reader->get('locked_role'))->toBe('operations')
        // The control: a plain read in the same transaction still sees the snapshot,
        // which is why a check made under the lock must use the locking read.
        ->and($reader->get('plain_role_after_commit'))->toBeNull();
})->repeat(3);

it('refuses to lock outside a transaction', function (): void {
    $id = committedUser();

    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => app(Users::class)->lockForUpdate($id))
        ->toThrow(TransactionRequired::class, "Users::lockForUpdate() must run inside the caller's database transaction.");
});

it('never commits the caller\'s transaction', function (): void {
    $id = committedUser();

    DB::beginTransaction();
    DB::table('users')->where('id', $id)->update(['name' => 'Changed In The Caller']);
    $locked = app(Users::class)->lockForUpdate($id);
    $level = DB::transactionLevel();
    DB::rollBack();

    expect($locked->platformRole)->toBeNull()
        ->and($level)->toBe(1)
        ->and(DB::table('users')->where('id', $id)->value('name'))->toBe('Ada Lovelace');
});

it('never rolls back the caller\'s transaction, even when the user is missing', function (): void {
    $id = committedUser(PlatformRole::Support->value);

    DB::beginTransaction();
    DB::table('users')->where('id', $id)->update(['name' => 'Changed In The Caller']);

    expect(fn () => app(Users::class)->lockForUpdate($id + 1000))->toThrow(UserNotFound::class);

    $level = DB::transactionLevel();
    DB::commit();

    expect($level)->toBe(1)
        ->and(DB::table('users')->where('id', $id)->value('name'))->toBe('Changed In The Caller');
});
