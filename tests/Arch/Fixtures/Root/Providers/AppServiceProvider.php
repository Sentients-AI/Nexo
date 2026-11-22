<?php

namespace Tests\Arch\Fixtures\Root\Providers;

use Tests\Arch\Fixtures\Root\Modules\Vendors\Application\ApproveVendor;

/** Violates the composition-root rule: it reaches into a use case. */
final class AppServiceProvider
{
    public function register(): string
    {
        return ApproveVendor::class;
    }
}
