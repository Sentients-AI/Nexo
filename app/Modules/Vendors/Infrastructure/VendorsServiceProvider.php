<?php

namespace App\Modules\Vendors\Infrastructure;

use App\Modules\Identity\Contracts\ActiveMembershipCheck;
use App\Modules\Vendors\Infrastructure\Console\AssignFirstOwnerCommand;
use App\Modules\Vendors\Infrastructure\Console\CreateVendorCommand;
use App\Modules\Vendors\Infrastructure\Console\ReassignOwnerCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Vendors module's provider (ADR-0010 §4, interpretation 1), registered
 * from bootstrap/providers.php. It registers the module's console commands,
 * and only when the application runs in the console.
 */
final class VendorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ActiveMembershipCheck::class, DatabaseActiveMembershipCheck::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateVendorCommand::class, AssignFirstOwnerCommand::class, ReassignOwnerCommand::class]);
        }
    }
}
