<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Infrastructure;

use App\Modules\Identity\Contracts\ActiveMembershipCheck;
use App\Modules\Identity\Contracts\TransactionRequired;
use App\Modules\Vendors\Domain\VendorMember;

final class DatabaseActiveMembershipCheck implements ActiveMembershipCheck
{
    public function hasActiveMembership(int $userId): bool
    {
        $query = VendorMember::query()->toBase();
        if ($query->getConnection()->transactionLevel() === 0) {
            throw TransactionRequired::for('ActiveMembershipCheck::hasActiveMembership()');
        }

        return $query->where('user_id', $userId)->where('active_marker', 1)
            ->sharedLock()->first(['id']) !== null;
    }
}
