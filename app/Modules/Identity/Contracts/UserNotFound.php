<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use DomainException;

/**
 * No user has this id. Raised instead of treating the id as "a user without
 * a platform role".
 */
final class UserNotFound extends DomainException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct("User {$userId} does not exist.");
    }
}
