<?php

namespace Tests\Arch\Fixtures\C1Allowed\Modules\Identity\Application;

use Tests\Arch\Fixtures\C1Allowed\Modules\Identity\Domain\User;

/** Satisfies C1: Identity uses its own User, with nothing from the composition root or App\Models. */
final class RegisterUser
{
    public function handle(): User
    {
        return new User;
    }
}
