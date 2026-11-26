<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Application;

use App\Modules\Vendors\Domain\MemberRole;
use App\Modules\Vendors\Domain\MemberStatus;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorMember;
use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class DeactivateMember
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Actor $actor, int $vendorId, int $memberId): void
    {
        DB::transaction(function () use ($actor, $vendorId, $memberId): void {
            $members = VendorMember::query()->where('vendor_id', $vendorId)->whereKey($memberId);
            if (! $members->exists()) {
                throw (new ModelNotFoundException)->setModel(VendorMember::class, [$memberId]);
            }

            Vendor::query()->lockForUpdate()->findOrFail($vendorId);
            $member = $members->clone()->lockForUpdate()->firstOrFail();
            if ($member->status !== MemberStatus::Active) {
                throw new MemberNotActive("Membership {$memberId} is no longer active.");
            }
            if ($member->role === MemberRole::Owner) {
                throw new CannotDeactivateOwner('Transfer ownership before deactivating the active owner.');
            }

            $before = $this->snapshot($member);
            if ($members->where('status', MemberStatus::Active->value)->where('role', $member->role->value)->update([
                'status' => MemberStatus::Deactivated->value,
                'deactivated_at' => Date::now()->format('Y-m-d H:i:s.u'),
            ]) !== 1) {
                throw new LogicException('Locked membership changed during deactivation.');
            }

            $this->audit->record($actor, 'vendor_member.deactivated', 'vendor_member', $memberId,
                $before, $this->snapshot($member->refresh()),
            );
        }, 3);
    }

    /** @return array{status: string, deactivated_at: string|null} */
    private function snapshot(VendorMember $member): array
    {
        return [
            'status' => $member->status->value,
            'deactivated_at' => $member->deactivated_at?->format('Y-m-d H:i:s.u'),
        ];
    }
}
