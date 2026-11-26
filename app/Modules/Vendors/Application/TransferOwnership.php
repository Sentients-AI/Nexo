<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Application;

use App\Shared\Audit\Actor;

final readonly class TransferOwnership extends ChangeOwnership
{
    public function handle(Actor $actor, int $vendorId, int $expectedOwnerId, int $replacementId): void
    {
        $this->change($actor, 'vendor.ownership_transferred', $vendorId, $expectedOwnerId, $replacementId);
    }
}
