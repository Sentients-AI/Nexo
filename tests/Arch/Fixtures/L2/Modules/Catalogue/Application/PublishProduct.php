<?php

namespace Tests\Arch\Fixtures\L2\Modules\Catalogue\Application;

use Tests\Arch\Fixtures\L2\Modules\Catalogue\Http\ProductPage;

/** Violates L2: a use case depends on its module's Http layer. */
final class PublishProduct
{
    public function handle(): ProductPage
    {
        return new ProductPage;
    }
}
