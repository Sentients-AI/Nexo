<?php

namespace Tests\Arch\Fixtures\M1Allowed\Modules\Ordering\Application;

use Tests\Arch\Fixtures\M1Allowed\Modules\Inventory\Contracts\InventoryReservations;

/** Satisfies M1 and M4: Ordering may call Inventory, through its Contracts. */
final class PlaceCheckout
{
    public function __construct(private readonly InventoryReservations $reservations) {}
}
