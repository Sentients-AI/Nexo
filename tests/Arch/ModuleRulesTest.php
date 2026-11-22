<?php

use Tests\Arch\Fixtures\M5\CyclicDependencies;
use Tests\Arch\Support\Fixture;
use Tests\Arch\Support\Registry;
use Tests\Arch\Support\Rules;

const FIXTURES = 'Tests\Arch\Fixtures';

it('imports other modules only from their Contracts (M1)', function () {
    Rules::modulesImportOnlyContracts('App\Modules');
});

it('rejects an import of another module\'s Domain (M1 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::modulesImportOnlyContracts(FIXTURES.'\M1\Modules'));

    // Pest shortens long target names with an ellipsis; the dependency is printed in full.
    expect($failure->getMessage())->toContain("not to use 'Tests\Arch\Fixtures\M1\Modules\Inventory'");
});

it('accepts an allowed call through Contracts (M1 and M4 control)', function () {
    Rules::modulesImportOnlyContracts(FIXTURES.'\M1Allowed\Modules');
    Rules::onlyAllowedDependencies(FIXTURES.'\M1Allowed\Modules');
});

it('keeps the shared kernel free of modules (M2)', function () {
    Rules::sharedImportsNoModule('App\Shared', 'App\Modules');
});

it('rejects a module import in the shared kernel (M2 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::sharedImportsNoModule(FIXTURES.'\M2\Shared', FIXTURES.'\M2\Modules'));

    expect($failure->getMessage())->toContain('M2\Shared');
});

it('calls only the Contracts the dependency matrix allows (M4)', function () {
    Rules::onlyAllowedDependencies('App\Modules');
});

it('rejects a call the matrix does not allow (M4 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::onlyAllowedDependencies(FIXTURES.'\M4\Modules'));

    expect($failure->getMessage())->toContain("not to use 'Tests\Arch\Fixtures\M4\Modules\Ordering\Contracts'");
});

it('has no cycle in the synchronous dependencies (M5)', function () {
    Rules::acyclic(Registry::DEPENDENCIES);
});

it('rejects a dependency cycle (M5 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::acyclic(CyclicDependencies::GRAPH));

    expect($failure->getMessage())->toContain('Vendors -> Catalogue -> Inventory -> Vendors');
});
