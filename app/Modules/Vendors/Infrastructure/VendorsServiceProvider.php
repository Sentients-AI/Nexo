<?php

namespace App\Modules\Vendors\Infrastructure;

use App\Modules\Vendors\Infrastructure\Console\CreateVendorCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Vendors module's provider (ADR-0010 §4, interpretation 1), registered
 * from bootstrap/providers.php. It registers the module's console commands,
 * and only when the application runs in the console.
 */
final class VendorsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateVendorCommand::class]);
        }
    }
}
