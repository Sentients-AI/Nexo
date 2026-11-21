<?php

use Tests\Arch\Fixtures\M5\CyclicDependencies;
use Tests\Arch\Support\Fixture;
use Tests\Arch\Support\Registry;
use Tests\Arch\Support\Rules;

it('has no cycle in the synchronous dependencies (M5)', function () {
    Rules::acyclic(Registry::DEPENDENCIES);
});

it('rejects a dependency cycle (M5 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::acyclic(CyclicDependencies::GRAPH));

    expect($failure->getMessage())->toContain('Vendors -> Catalogue -> Inventory -> Vendors');
});
