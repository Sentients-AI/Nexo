<?php

namespace Tests\Arch\Fixtures\C1Legacy\Modules\Identity\Application;

use Tests\Arch\Fixtures\C1Legacy\Models\User;

/** Violates C1: Identity's App\Models\User exception ended when User moved to Identity\Domain (#33). */
final class RegisterUser
{
    public function handle(): User
    {
        return new User;
    }
}
