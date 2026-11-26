<?php

declare(strict_types=1);

use App\Modules\Identity\Application\GrantPlatformRole;
use App\Modules\Identity\Application\UserHasActiveMembership;
use App\Modules\Identity\Contracts\ActiveMembershipCheck;
use App\Modules\Identity\Contracts\PlatformRole;
use App\Modules\Identity\Domain\User;
use App\Modules\Vendors\Application\AssignFirstOwner;
use App\Modules\Vendors\Application\CannotDeactivateOwner;
use App\Modules\Vendors\Application\DeactivateMember;
use App\Modules\Vendors\Application\InvalidOwnershipTarget;
use App\Modules\Vendors\Application\MemberNotActive;
use App\Modules\Vendors\Application\OwnershipChanged;
use App\Modules\Vendors\Application\ReassignOwner;
use App\Modules\Vendors\Application\TransferOwnership;
use App\Modules\Vendors\Application\UserAlreadyMember;
use App\Modules\Vendors\Application\UserIsPlatformStaff;
use App\Modules\Vendors\Application\VendorAlreadyOwned;
use App\Modules\Vendors\Application\VendorSuspendedForMembership;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorMember;
use App\Modules\Vendors\Infrastructure\DatabaseActiveMembershipCheck;
use App\Shared\Audit\Actor;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Audit\AuditInsertFailure;

/** @return array{Actor, int, int, int, int, int} */
function membershipFixture(): array
{
    $actor = Actor::console('test:vendor-membership');
    $vendor = Vendor::factory()->create()->id;
    $user = User::factory()->create()->id;
    $otherUser = User::factory()->create()->id;
    $owner = app(AssignFirstOwner::class)->handle($actor, $vendor, $user)->id;
    $member = DB::table('vendor_members')->insertGetId(['vendor_id' => $vendor, 'user_id' => $otherUser, 'role' => 'support']);

    return [$actor, $vendor, $owner, $member, $user, $otherUser];
}

it('assigns the first owner to the explicit vendor and refuses existing ownership, membership, staff and suspension', function (): void {
    [$actor, $vendor, $owner, , $user] = membershipFixture();
    $other = Vendor::factory()->create()->id;
    $staff = User::factory()->create()->id;
    $newUser = User::factory()->create()->id;
    $assign = app(AssignFirstOwner::class);
    DB::table('users')->where('id', $staff)->update(['platform_role' => 'admin']);
    $suspended = Vendor::factory()->create()->id;
    DB::table('vendors')->where('id', $suspended)->update(['status' => 'suspended']);

    expect(VendorMember::findOrFail($owner)->vendor_id)->toBe($vendor)
        ->and(fn () => $assign->handle($actor, $vendor, $newUser))->toThrow(VendorAlreadyOwned::class)
        ->and(fn () => $assign->handle($actor, $other, $user))->toThrow(UserAlreadyMember::class)
        ->and(fn () => $assign->handle($actor, $other, $staff))->toThrow(UserIsPlatformStaff::class)
        ->and(fn () => $assign->handle($actor, $suspended, $newUser))->toThrow(VendorSuspendedForMembership::class)
        ->and(DB::table('audit_log')->count())->toBe(1);
});

it('transfers and reassigns existing owners without moving members between vendors', function (string $operation, string $action): void {
    [$actor, $vendor, $owner, $member] = membershipFixture();
    $other = Vendor::factory()->create()->id;
    $foreignUser = User::factory()->create()->id;
    $foreign = DB::table('vendor_members')->insertGetId(['vendor_id' => $other, 'user_id' => $foreignUser, 'role' => 'manager']);

    expect(fn () => app($operation)->handle($actor, $vendor, $owner, $foreign))->toThrow(InvalidOwnershipTarget::class)
        ->and(DB::table('audit_log')->count())->toBe(1);
    app($operation)->handle($actor, $vendor, $owner, $member);

    expect(DB::table('vendor_members')->where('vendor_id', $vendor)->where('owner_marker', 1)->pluck('id')->all())->toBe([$member])
        ->and(DB::table('vendor_members')->where('id', $owner)->value('role'))->toBe('manager')
        ->and(DB::table('audit_log')->where('action', $action)->count())->toBe(1)
        ->and(fn () => app($operation)->handle($actor, $vendor, $owner, $member))->toThrow(OwnershipChanged::class);
})->with([
    'transfer' => [TransferOwnership::class, 'vendor.ownership_transferred'],
    'operator reassignment' => [ReassignOwner::class, 'vendor.owner_reassigned'],
]);

it('refuses cross-vendor deactivation and owner deactivation and retains terminal history', function (): void {
    [$actor, $vendor, $owner, $member] = membershipFixture();
    $other = Vendor::factory()->create()->id;
    $deactivate = app(DeactivateMember::class);

    expect(fn () => $deactivate->handle($actor, $other, $member))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $deactivate->handle($actor, $vendor, $owner))->toThrow(CannotDeactivateOwner::class)
        ->and(DB::table('audit_log')->count())->toBe(1);

    $deactivate->handle($actor, $vendor, $member);
    expect(DB::table('vendor_members')->find($member)->status)->toBe('deactivated')
        ->and(DB::table('vendor_members')->find($member)->deactivated_at)->not->toBeNull()
        ->and(fn () => $deactivate->handle($actor, $vendor, $member))->toThrow(MemberNotActive::class)
        ->and(DB::table('audit_log')->count())->toBe(2);
});

it('resolves the membership port and permits staff grants only after deactivation', function (): void {
    [$actor, $vendor, , $member, $ownerUser, $user] = membershipFixture();
    expect(app(ActiveMembershipCheck::class))->toBeInstanceOf(DatabaseActiveMembershipCheck::class)
        ->and(fn () => app(GrantPlatformRole::class)->handle($actor, $ownerUser, PlatformRole::Admin))->toThrow(UserHasActiveMembership::class);

    app(DeactivateMember::class)->handle($actor, $vendor, $member);
    app(GrantPlatformRole::class)->handle($actor, $user, PlatformRole::Admin);
    expect(DB::table('users')->where('id', $user)->value('platform_role'))->toBe('admin')
        ->and(DB::table('audit_log')->where('action', 'user.platform_role_granted')->count())->toBe(1);
});

it('fails the staff grant closed if the membership port is unavailable', function (): void {
    $user = User::factory()->create()->id;
    unset(app()[ActiveMembershipCheck::class]);

    expect(fn () => app(GrantPlatformRole::class))->toThrow(BindingResolutionException::class)
        ->and(DB::table('users')->where('id', $user)->value('platform_role'))->toBeNull()
        ->and(DB::table('audit_log')->count())->toBe(0);
});

it('leaves the staff role unchanged if its locking read fails', function (): void {
    $user = User::factory()->create()->id;
    DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection): void {
        if (str_contains($sql, 'vendor_members')) {
            throw new QueryException((string) $connection->getName(), $sql, $bindings, new PDOException('Membership check failed.'));
        }
    });

    expect(fn () => app(GrantPlatformRole::class)->handle(Actor::console('test:vendor-membership'), $user, PlatformRole::Admin))
        ->toThrow(QueryException::class)
        ->and(DB::table('users')->where('id', $user)->value('platform_role'))->toBeNull()
        ->and(DB::table('audit_log')->count())->toBe(0);
});

it('rolls back each business write when its audit insert fails', function (string $operation): void {
    [$actor, $vendor, $owner, $member] = membershipFixture();
    $freeUser = User::factory()->create()->id;
    $freeVendor = Vendor::factory()->create()->id;
    AuditInsertFailure::injectOnce();

    $attempt = match ($operation) {
        'assign' => fn () => app(AssignFirstOwner::class)->handle($actor, $freeVendor, $freeUser),
        'transfer' => fn () => app(TransferOwnership::class)->handle($actor, $vendor, $owner, $member),
        'reassign' => fn () => app(ReassignOwner::class)->handle($actor, $vendor, $owner, $member),
        'deactivate' => fn () => app(DeactivateMember::class)->handle($actor, $vendor, $member),
        'grant' => fn () => app(GrantPlatformRole::class)->handle($actor, $freeUser, PlatformRole::Admin),
    };
    expect($attempt)->toThrow(QueryException::class, AuditInsertFailure::MESSAGE)
        ->and(DB::table('vendor_members')->count())->toBe(2)
        ->and(DB::table('vendor_members')->find($owner)->role)->toBe('owner')
        ->and(DB::table('vendor_members')->find($member)->role)->toBe('support')
        ->and(DB::table('vendor_members')->find($member)->status)->toBe('active')
        ->and(DB::table('users')->where('id', $freeUser)->value('platform_role'))->toBeNull()
        ->and(DB::table('audit_log')->count())->toBe(1);
})->with(['assign', 'transfer', 'reassign', 'deactivate', 'grant']);

it('validates command IDs and audits only named console provenance', function (): void {
    $vendor = Vendor::factory()->create()->id;
    $user = User::factory()->create()->id;
    $this->artisan('vendors:assign-first-owner', ['vendor' => '0', 'user' => (string) $user])->assertExitCode(2);
    $this->artisan('vendors:assign-first-owner', ['vendor' => (string) $vendor, 'user' => (string) $user])->assertSuccessful();

    $owner = DB::table('vendor_members')->where('vendor_id', $vendor)->where('owner_marker', 1)->value('id');
    $replacementUser = User::factory()->create()->id;
    $replacement = DB::table('vendor_members')->insertGetId([
        'vendor_id' => $vendor, 'user_id' => $replacementUser, 'role' => 'manager',
    ]);
    $this->artisan('vendors:reassign-owner', [
        'vendor' => (string) $vendor, 'owner' => (string) $owner, 'replacement' => (string) $replacement,
    ])->assertSuccessful();

    $audit = DB::table('audit_log')->orderBy('id')->get();
    expect($audit->pluck('actor_label')->all())->toBe(['vendors:assign-first-owner', 'vendors:reassign-owner'])
        ->and($audit->pluck('actor_user_id')->all())->toBe([null, null])
        ->and($audit->pluck('actor_type')->all())->toBe(['console', 'console']);
});
