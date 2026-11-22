<?php

namespace Tests\Arch\Fixtures\M1\Modules\Ordering\Application;

use Tests\Arch\Fixtures\M1\Modules\Inventory\Domain\StockLevel;

/** Violates M1: imports another module's Domain instead of its Contracts. */
final class PlaceCheckout
{
    public function handle(StockLevel $stock): void {}
}
