<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * A user's row as read by Users::lockForUpdate(), under the lock. It carries
 * the values; it is not evidence that the lock is still held (ADR-0011
 * Decision 10, amendment A).
 */
final readonly class LockedUser
{
    public function __construct(
        public int $userId,
        public ?PlatformRole $platformRole,
    ) {}

    public function isStaff(): bool
    {
        return $this->platformRole !== null;
    }
}
