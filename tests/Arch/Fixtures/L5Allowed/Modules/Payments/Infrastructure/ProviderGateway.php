<?php

namespace Tests\Arch\Fixtures\L5Allowed\Modules\Payments\Infrastructure;

use Tests\Arch\Fixtures\Stubs\ProviderSdk\Client;

/** Satisfies L5: the provider SDK inside Payments\Infrastructure. */
final class ProviderGateway
{
    public function __construct(private readonly Client $provider) {}
}
