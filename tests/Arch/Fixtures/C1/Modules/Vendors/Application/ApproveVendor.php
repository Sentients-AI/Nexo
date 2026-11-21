<?php

namespace Tests\Arch\Fixtures\C1\Modules\Vendors\Application;

use Tests\Arch\Fixtures\C1\Http\Middleware\SetLocale;

/** Violates C1: a module imports the composition root. */
final class ApproveVendor
{
    public function __construct(private readonly SetLocale $locale) {}
}
