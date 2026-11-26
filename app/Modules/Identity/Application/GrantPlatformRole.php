<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\ActiveMembershipCheck;
use App\Modules\Identity\Contracts\PlatformRole;
use App\Modules\Identity\Contracts\Users;
use App\Modules\Identity\Domain\User;
use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final readonly class GrantPlatformRole
{
    public function __construct(
        private Users $users,
        private ActiveMembershipCheck $memberships,
        private AuditRecorder $audit,
    ) {}

    public function handle(Actor $actor, int $userId, PlatformRole $role): void
    {
        DB::transaction(function () use ($actor, $userId, $role): void {
            $user = $this->users->lockForUpdate($userId);
            if ($this->memberships->hasActiveMembership($userId)) {
                throw new UserHasActiveMembership("User {$userId} has an active vendor membership.");
            }

            if ($user->platformRole === $role) {
                return;
            }

            $query = User::query()->whereKey($userId);
            $query->update(['platform_role' => $role->value]);
            $this->audit->record($actor, 'user.platform_role_granted', 'user', $userId,
                ['platform_role' => $user->platformRole?->value],
                ['platform_role' => $query->toBase()->value('platform_role')],
            );
        }, 3);
    }
}
