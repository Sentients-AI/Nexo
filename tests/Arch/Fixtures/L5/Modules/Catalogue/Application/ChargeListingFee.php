<?php

namespace Tests\Arch\Fixtures\L5\Modules\Catalogue\Application;

use Tests\Arch\Fixtures\Stubs\ProviderSdk\Client;

/** Violates L5: a provider SDK outside Payments\Infrastructure. */
final class ChargeListingFee
{
    public function __construct(private readonly Client $provider) {}
}
