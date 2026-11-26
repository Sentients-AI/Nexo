<?php

declare(strict_types=1);

use App\Modules\Identity\Application\UserHasActiveMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Vendors\Application\CannotDeactivateOwner;
use App\Modules\Vendors\Application\InvalidOwnershipTarget;
use App\Modules\Vendors\Application\MemberNotActive;
use App\Modules\Vendors\Application\OwnershipChanged;
use App\Modules\Vendors\Application\UserAlreadyMember;
use App\Modules\Vendors\Application\UserIsPlatformStaff;
use App\Modules\Vendors\Application\VendorAlreadyOwned;
use App\Modules\Vendors\Domain\Vendor;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\WorkerResult;
use Tests\Concurrency\Scenarios\RunMembershipOperation;
use Tests\Concurrency\Scenarios\UnserializedMembershipAssignment;

it('preserves safety, progress and one audit row per successful membership mutation', function (string $race): void {
    $vendors = Vendor::factory()->count(2)->create()->modelKeys();
    $users = User::factory()->count(3)->create()->modelKeys();
    [$vendor, $otherVendor] = $vendors;
    [$user, $targetUser, $thirdUser] = $users;

    try {
        $existing = in_array($race, ['transfer/deactivate', 'transfer/transfer', 'grant/deactivate', 'deactivate/deactivate'], true);
        $member = static fn (int $userId, string $role): int => DB::table('vendor_members')->insertGetId([
            'vendor_id' => $vendor, 'user_id' => $userId, 'role' => $role,
        ]);
        $owner = $existing ? $member($user, 'owner') : 0;
        $target = $existing ? $member($targetUser, 'manager') : 0;
        $replacement = $race === 'transfer/transfer' ? $member($thirdUser, 'support') : 0;

        [$calls, $allowed] = match ($race) {
            'same vendor' => [
                [['assign', $vendor, $user, 0], ['assign', $vendor, $targetUser, 0]],
                [['success', VendorAlreadyOwned::class], [VendorAlreadyOwned::class, 'success']],
            ],
            'two vendors' => [
                [['assign', $vendor, $user, 0], ['assign', $otherVendor, $user, 0]],
                [['success', UserAlreadyMember::class], [UserAlreadyMember::class, 'success']],
            ],
            'duplicate' => [
                [['assign', $vendor, $user, 0], ['assign', $vendor, $user, 0]],
                [['success', VendorAlreadyOwned::class], [VendorAlreadyOwned::class, 'success'], ['success', UserAlreadyMember::class], [UserAlreadyMember::class, 'success']],
            ],
            'transfer/deactivate' => [
                [['transfer', $vendor, $owner, $target], ['deactivate', $vendor, $target, 0]],
                [['success', CannotDeactivateOwner::class], [InvalidOwnershipTarget::class, 'success']],
            ],
            'transfer/transfer' => [
                [['transfer', $vendor, $owner, $target], ['transfer', $vendor, $owner, $replacement]],
                [['success', OwnershipChanged::class], [OwnershipChanged::class, 'success']],
            ],
            'grant/assign' => [
                [['grant', 0, $user, 0], ['assign', $vendor, $user, 0]],
                [['success', UserIsPlatformStaff::class], [UserHasActiveMembership::class, 'success']],
            ],
            'grant/deactivate' => [
                [['grant', 0, $targetUser, 0], ['deactivate', $vendor, $target, 0]],
                [['success', 'success'], [UserHasActiveMembership::class, 'success']],
            ],
            'deactivate/deactivate' => [
                [['deactivate', $vendor, $target, 0], ['deactivate', $vendor, $target, 0]],
                [['success', MemberNotActive::class], [MemberNotActive::class, 'success']],
            ],
        };

        $results = Concurrently::run(app(), RunMembershipOperation::class, workers: 2, arguments: ['calls' => $calls]);
        $outcomes = array_map(fn (WorkerResult $result) => $result->get('outcome'), $results);
        expect($allowed)->toContain($outcomes);

        $winner = array_search('success', $outcomes, true);
        $owners = DB::table('vendor_members')->whereIn('vendor_id', $vendors)->where('owner_marker', 1)->get();
        if ($existing) {
            $expectedOwner = match ($race) {
                'transfer/deactivate' => $winner === 0 ? $target : $owner,
                'transfer/transfer' => $winner === 0 ? $target : $replacement,
                default => $owner,
            };
            expect($owners->pluck('id')->all())->toBe([$expectedOwner]);
            $deactivated = in_array($race, ['grant/deactivate', 'deactivate/deactivate'], true)
                || ($race === 'transfer/deactivate' && $winner === 1);
            expect(DB::table('vendor_members')->where('id', $target)->value('status'))->toBe($deactivated ? 'deactivated' : 'active');
        } elseif ($race === 'grant/assign' && $winner === 0) {
            expect($owners)->toHaveCount(0);
        } else {
            expect($owners)->toHaveCount(1)
                ->and($owners->sole()->vendor_id)->toBe($calls[$winner][1])
                ->and($owners->sole()->user_id)->toBe($calls[$winner][2]);
        }

        expect(DB::table('vendor_members')->join('users', 'users.id', '=', 'vendor_members.user_id')
            ->whereIn('users.id', $users)->whereNotNull('users.platform_role')->where('active_marker', 1)->exists())->toBeFalse();
        if ($race === 'grant/assign' || $race === 'grant/deactivate') {
            $grantUser = $calls[0][2];
            expect(DB::table('users')->where('id', $grantUser)->value('platform_role'))
                ->toBe($outcomes[0] === 'success' ? 'admin' : null);
        }
        $auditActions = [
            'assign' => 'vendor_member.first_owner_assigned',
            'transfer' => 'vendor.ownership_transferred',
            'deactivate' => 'vendor_member.deactivated',
            'grant' => 'user.platform_role_granted',
        ];
        $expectedActions = [];
        foreach ($outcomes as $index => $outcome) {
            if ($outcome === 'success') {
                $expectedActions[] = $auditActions[$calls[$index][0]];
            }
        }
        sort($expectedActions);
        expect(DB::table('audit_log')->where('actor_label', 'test:membership-race')->orderBy('action')->pluck('action')->all())
            ->toBe($expectedActions);
    } finally {
        DB::table('audit_log')->where('actor_label', 'test:membership-race')->delete();
        DB::table('vendor_members')->whereIn('vendor_id', $vendors)->delete();
        DB::table('vendors')->whereIn('id', $vendors)->delete();
        DB::table('users')->whereIn('id', $users)->delete();
    }
})->with([
    'same vendor', 'two vendors', 'duplicate', 'transfer/deactivate', 'transfer/transfer',
    'grant/assign', 'grant/deactivate', 'deactivate/deactivate',
]);

it('detects staff-membership overlap when checks and writes omit user-row coordination', function (): void {
    $vendor = Vendor::factory()->create()->id;
    $user = User::factory()->create()->id;
    try {
        $results = Concurrently::run(app(), UnserializedMembershipAssignment::class, workers: 2,
            arguments: ['vendor' => $vendor, 'user' => $user],
        );

        expect(array_map(fn (WorkerResult $result) => $result->get('outcome'), $results))->toBe(['success', 'success'])
            ->and(DB::table('vendor_members')->join('users', 'users.id', '=', 'vendor_members.user_id')
                ->where('users.id', $user)->whereNotNull('users.platform_role')->where('active_marker', 1)->exists())->toBeTrue();
    } finally {
        DB::table('vendor_members')->where('vendor_id', $vendor)->delete();
        DB::table('vendors')->where('id', $vendor)->delete();
        DB::table('users')->where('id', $user)->delete();
    }
});
