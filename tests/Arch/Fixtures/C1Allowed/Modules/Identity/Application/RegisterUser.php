<?php

namespace Tests\Arch\Fixtures\C1Allowed\Modules\Identity\Application;

use Tests\Arch\Fixtures\C1Allowed\Models\User;

/** Satisfies C1: Identity imports App\Models\User, the one allowed exception. */
final class RegisterUser
{
    public function handle(): User
    {
        return new User;
    }
}
