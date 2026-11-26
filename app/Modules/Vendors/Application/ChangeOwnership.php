<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Application;

use App\Modules\Vendors\Domain\MemberRole;
use App\Modules\Vendors\Domain\MemberStatus;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorMember;
use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** The shared audited transaction used by the two public ownership boundaries. */
abstract readonly class ChangeOwnership
{
    public function __construct(private AuditRecorder $audit) {}

    protected function change(Actor $actor, string $action, int $vendorId, int $expectedOwnerId, int $replacementId): void
    {
        DB::transaction(function () use ($actor, $action, $vendorId, $expectedOwnerId, $replacementId): void {
            if ($expectedOwnerId === $replacementId) {
                throw new InvalidOwnershipTarget('The replacement must be a different membership.');
            }

            $members = VendorMember::query()->where('vendor_id', $vendorId);
            $candidateIds = $members->clone()->whereKey([$expectedOwnerId, $replacementId])
                ->orderBy('id')->pluck('id');
            Vendor::query()->lockForUpdate()->findOrFail($vendorId);

            $locked = [];
            foreach ($candidateIds as $id) {
                $locked[$id] = $members->clone()->lockForUpdate()->find($id);
            }
            $previous = $locked[$expectedOwnerId] ?? null;
            $replacement = $locked[$replacementId] ?? null;
            if ($previous === null || $previous->status !== MemberStatus::Active || $previous->role !== MemberRole::Owner) {
                throw new OwnershipChanged("Vendor {$vendorId} no longer has the expected owner.");
            }
            if ($replacement === null || $replacement->status !== MemberStatus::Active) {
                throw new InvalidOwnershipTarget("The replacement is not an active member of vendor {$vendorId}.");
            }

            $before = $this->snapshot($previous, $replacement);
            if ($members->clone()->whereKey($previous->id)->where('status', MemberStatus::Active->value)
                ->where('role', MemberRole::Owner->value)->update(['role' => MemberRole::Manager->value]) !== 1) {
                throw new LogicException('Locked owner changed during demotion.');
            }
            if ($members->clone()->whereKey($replacement->id)->where('status', MemberStatus::Active->value)
                ->where('role', $replacement->role->value)->update(['role' => MemberRole::Owner->value]) !== 1) {
                throw new LogicException('Locked replacement changed during promotion.');
            }

            $this->audit->record($actor, $action, 'vendor', $vendorId, $before,
                $this->snapshot($previous->refresh(), $replacement->refresh()),
            );
        }, 3);
    }

    /** @return array{previous_member_id: int, previous_role: string, replacement_member_id: int, replacement_role: string} */
    private function snapshot(VendorMember $previous, VendorMember $replacement): array
    {
        return [
            'previous_member_id' => $previous->id,
            'previous_role' => $previous->role->value,
            'replacement_member_id' => $replacement->id,
            'replacement_role' => $replacement->role->value,
        ];
    }
}
