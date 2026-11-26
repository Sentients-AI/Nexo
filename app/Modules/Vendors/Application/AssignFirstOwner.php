<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Application;

use App\Modules\Identity\Contracts\ActiveMembershipCheck;
use App\Modules\Identity\Contracts\Users;
use App\Modules\Vendors\Domain\MemberRole;
use App\Modules\Vendors\Domain\MemberStatus;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorMember;
use App\Modules\Vendors\Domain\VendorStatus;
use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final readonly class AssignFirstOwner
{
    public function __construct(
        private Users $users,
        private ActiveMembershipCheck $memberships,
        private AuditRecorder $audit,
    ) {}

    public function handle(Actor $actor, int $vendorId, int $userId): VendorMember
    {
        return DB::transaction(function () use ($actor, $vendorId, $userId): VendorMember {
            $user = $this->users->lockForUpdate($userId);
            $vendor = Vendor::query()->lockForUpdate()->findOrFail($vendorId);

            if ($user->platformRole !== null) {
                throw new UserIsPlatformStaff("User {$userId} is platform staff.");
            }
            if ($vendor->status === VendorStatus::Suspended) {
                throw new VendorSuspendedForMembership("Vendor {$vendorId} is suspended.");
            }
            if (VendorMember::query()->where('vendor_id', $vendorId)->where('owner_marker', 1)->lockForUpdate()->first(['id']) !== null) {
                throw new VendorAlreadyOwned("Vendor {$vendorId} already has an active owner.");
            }
            if ($this->memberships->hasActiveMembership($userId)) {
                throw new UserAlreadyMember("User {$userId} already has an active membership.");
            }

            $member = VendorMember::query()->forceCreate([
                'vendor_id' => $vendorId,
                'user_id' => $userId,
                'role' => MemberRole::Owner,
                'status' => MemberStatus::Active,
            ]);
            $member->refresh();
            $this->audit->record($actor, 'vendor_member.first_owner_assigned', 'vendor_member', $member->id, null, [
                'vendor_id' => $member->vendor_id,
                'user_id' => $member->user_id,
                'role' => $member->role->value,
                'status' => $member->status->value,
            ]);

            return $member;
        }, 3);
    }
}
