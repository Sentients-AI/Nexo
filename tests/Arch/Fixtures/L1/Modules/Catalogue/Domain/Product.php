<?php

namespace Tests\Arch\Fixtures\L1\Modules\Catalogue\Domain;

use Illuminate\Http\Request;

/** Violates L1: the domain reads the HTTP request. */
final class Product
{
    public function titleFrom(Request $request): string
    {
        return (string) $request->input('title');
    }
}
