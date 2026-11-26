<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/** Caller holds the user's exclusive row lock in the current default-connection transaction. */
interface ActiveMembershipCheck
{
    public function hasActiveMembership(int $userId): bool;
}
