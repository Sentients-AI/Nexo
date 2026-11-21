<?php

namespace Tests\Arch\Fixtures\RootAllowed\Providers;

use Tests\Arch\Fixtures\RootAllowed\Modules\Vendors\Contracts\VendorDirectory;
use Tests\Arch\Fixtures\RootAllowed\Modules\Vendors\Infrastructure\VendorsServiceProvider;

/** Satisfies the composition-root rule: registers a module provider and uses a contract (owner decision D4). */
final class AppServiceProvider
{
    /** @return list<class-string> */
    public function registered(): array
    {
        return [VendorsServiceProvider::class, VendorDirectory::class];
    }
}
