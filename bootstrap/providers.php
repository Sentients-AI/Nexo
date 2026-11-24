<?php

use App\Modules\Identity\Infrastructure\IdentityServiceProvider;
use App\Modules\Vendors\Infrastructure\VendorsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    VendorsServiceProvider::class,
];
