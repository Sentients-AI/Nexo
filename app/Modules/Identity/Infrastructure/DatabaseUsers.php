<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Contracts\LockedUser;
use App\Modules\Identity\Contracts\PlatformRole;
use App\Modules\Identity\Contracts\TransactionRequired;
use App\Modules\Identity\Contracts\UserNotFound;
use App\Modules\Identity\Contracts\Users;
use App\Modules\Identity\Domain\User;
use Illuminate\Database\Query\Builder;

/**
 * Identity's implementation of Users, over the `users` table on the User
 * model's connection (the default connection). It reads only `id` and
 * `platform_role`, and never begins, commits, rolls back or retries a
 * transaction: the caller owns it (ADR-0011 Decision 10).
 */
final class DatabaseUsers implements Users
{
    public function exists(int $userId): bool
    {
        return $this->query($userId)->exists();
    }

    public function platformRole(int $userId): ?PlatformRole
    {
        return $this->read($this->query($userId), $userId)->platformRole;
    }

    public function lockForUpdate(int $userId): LockedUser
    {
        $query = $this->query($userId);

        if ($query->getConnection()->transactionLevel() === 0) {
            throw TransactionRequired::for('Users::lockForUpdate()');
        }

        // A locking read: it waits for any other lock on the row and then
        // reads the latest committed values, not the transaction's snapshot.
        return $this->read($query->lockForUpdate(), $userId);
    }

    private function query(int $userId): Builder
    {
        return User::query()->toBase()->where('id', $userId);
    }

    private function read(Builder $query, int $userId): LockedUser
    {
        $row = $query->first(['id', 'platform_role']);

        if ($row === null) {
            throw new UserNotFound($userId);
        }

        $role = $row->platform_role ?? null;

        return new LockedUser(
            userId: $userId,
            platformRole: is_string($role) ? PlatformRole::from($role) : null,
        );
    }
}
