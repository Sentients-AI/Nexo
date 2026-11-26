<?php

declare(strict_types=1);

use App\Modules\Identity\Application\GrantPlatformRole;
use App\Modules\Identity\Contracts\PlatformRole;
use App\Modules\Identity\Domain\User;
use App\Modules\Vendors\Application\AssignFirstOwner;
use App\Modules\Vendors\Application\DeactivateMember;
use App\Modules\Vendors\Application\ReassignOwner;
use App\Modules\Vendors\Application\TransferOwnership;
use App\Modules\Vendors\Domain\Vendor;
use App\Shared\Audit\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\InjectedQueryFailure;

/** @return array{Closure, int, list<int>} */
function membershipMutation(string $operation): array
{
    $actor = Actor::console('test:membership-atomicity');
    $vendor = Vendor::factory()->create()->id;
    $users = User::factory()->count(2)->create()->modelKeys();
    [$user, $other] = $users;

    if (in_array($operation, ['transfer', 'reassign', 'deactivate'], true)) {
        $members = DB::table('vendor_members');
        $owner = $members->insertGetId(['vendor_id' => $vendor, 'user_id' => $user, 'role' => 'owner']);
        $member = $members->insertGetId(['vendor_id' => $vendor, 'user_id' => $other, 'role' => 'support']);
    }

    $mutate = match ($operation) {
        'assign' => fn () => app(AssignFirstOwner::class)->handle($actor, $vendor, $user),
        'grant' => fn () => app(GrantPlatformRole::class)->handle($actor, $user, PlatformRole::Finance),
        'transfer' => fn () => app(TransferOwnership::class)->handle($actor, $vendor, $owner, $member),
        'reassign' => fn () => app(ReassignOwner::class)->handle($actor, $vendor, $owner, $member),
        'deactivate' => fn () => app(DeactivateMember::class)->handle($actor, $vendor, $member),
    };

    return [$mutate, $vendor, $users];
}

function membershipState(int $vendor, array $users): array
{
    return [
        DB::table('vendor_members')->where('vendor_id', $vendor)->orderBy('id')->get()->toJson(),
        DB::table('users')->whereIn('id', $users)->orderBy('id')->pluck('platform_role')->all(),
        DB::table('audit_log')->where('actor_label', 'test:membership-atomicity')->count(),
    ];
}

function removeMembershipMutationFixtures(int $vendor, array $users): void
{
    DB::table('audit_log')->where('actor_label', 'test:membership-atomicity')->delete();
    DB::table('vendor_members')->where('vendor_id', $vendor)->delete();
    DB::table('vendors')->where('id', $vendor)->delete();
    DB::table('users')->whereIn('id', $users)->delete();
}

dataset('membership mutations', ['assign', 'grant', 'transfer', 'reassign', 'deactivate']);

it('leaves neither the mutation nor the audit entry after caller rollback', function (string $operation): void {
    [$mutate, $vendor, $users] = membershipMutation($operation);
    $before = membershipState($vendor, $users);
    DB::beginTransaction();
    try {
        $mutate();
        expect(membershipState($vendor, $users)[2])->toBe(1);
        DB::rollBack();
        expect(membershipState($vendor, $users))->toBe($before);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack(0);
        }
        removeMembershipMutationFixtures($vendor, $users);
    }
})->with('membership mutations');

it('retries an outer audit concurrency failure with exactly one committed record', function (string $operation): void {
    [$mutate, $vendor, $users] = membershipMutation($operation);
    try {
        $failure = InjectedQueryFailure::on('insert into `audit_log`', InjectedQueryFailure::DEADLOCK);
        $mutate();
        expect($failure->thrown)->toBe(1)
            ->and(membershipState($vendor, $users)[2])->toBe(1);
    } finally {
        removeMembershipMutationFixtures($vendor, $users);
    }
})->with('membership mutations');

it('surfaces exhausted retries with neither mutation nor audit record', function (string $operation): void {
    [$mutate, $vendor, $users] = membershipMutation($operation);
    $before = membershipState($vendor, $users);
    try {
        $failure = InjectedQueryFailure::on('insert into `audit_log`', InjectedQueryFailure::DEADLOCK, times: 3);
        expect($mutate)->toThrow(QueryException::class, 'Deadlock found when trying to get lock')
            ->and($failure->thrown)->toBe(3)
            ->and(membershipState($vendor, $users))->toBe($before);
    } finally {
        removeMembershipMutationFixtures($vendor, $users);
    }
})->with('membership mutations');
