<?php

namespace Tests\Arch\Fixtures\M5;

/** Violates M5: Vendors -> Catalogue -> Inventory -> Vendors. */
final class CyclicDependencies
{
    /** @var array<string, list<string>> */
    public const GRAPH = [
        'Identity' => [],
        'Vendors' => ['Identity', 'Catalogue'],
        'Catalogue' => ['Inventory'],
        'Inventory' => ['Vendors'],
    ];
}
