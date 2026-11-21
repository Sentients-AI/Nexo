<?php

namespace Tests\Arch\Fixtures\M2\Shared;

use Tests\Arch\Fixtures\M2\Modules\Catalogue\Contracts\ProductCatalogue;

/** Violates M2: the shared kernel imports a module, even through its Contracts. */
final class Money
{
    public function __construct(private readonly ProductCatalogue $catalogue) {}
}
