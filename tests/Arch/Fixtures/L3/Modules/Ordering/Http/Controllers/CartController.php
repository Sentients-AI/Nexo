<?php

namespace Tests\Arch\Fixtures\L3\Modules\Ordering\Http\Controllers;

use Illuminate\Support\Facades\DB;

/** Violates L3: the controller writes through the DB facade instead of calling a use case. */
final class CartController
{
    public function store(): void
    {
        DB::table('carts')->insert(['id' => 1]);
    }
}
