<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Contracts\Users;
use Illuminate\Support\ServiceProvider;

/**
 * The Identity module's provider (ADR-0010 §4, interpretation 1), registered
 * from bootstrap/providers.php. It binds Identity's contracts to their
 * implementations.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        Users::class => DatabaseUsers::class,
    ];
}
