<?php

use Tests\Arch\Support\Fixture;
use Tests\Arch\Support\Rules;

it('keeps modules and the shared kernel off the composition root (C1)', function () {
    Rules::compositionRootIsNotImported('App');
});

it('rejects a module that imports kernel-level Http (C1 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::compositionRootIsNotImported('Tests\Arch\Fixtures\C1'));

    expect($failure->getMessage())->toContain("not to use 'Tests\\Arch\\Fixtures\\C1\\Http'");
});

it('rejects Identity importing App\Models\User now that User has left it (C1 fixture, #33)', function () {
    $failure = Fixture::rejects(fn () => Rules::compositionRootIsNotImported('Tests\Arch\Fixtures\C1Legacy'));

    expect($failure->getMessage())->toContain("not to use 'Tests\\Arch\\Fixtures\\C1Legacy\\Models'");
});

it('lets Identity use its own Domain User (C1 control)', function () {
    Rules::compositionRootIsNotImported('Tests\Arch\Fixtures\C1Allowed');
});

it('keeps the composition root out of module internals (ADR-0010 §2a)', function () {
    Rules::compositionRootOnlyRegisters('App');
});

it('rejects a provider that reaches into a use case (§2a fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::compositionRootOnlyRegisters('Tests\Arch\Fixtures\Root'));

    expect($failure->getMessage())->toContain("not to use 'Tests\\Arch\\Fixtures\\Root\\Modules\\Vendors\\Application'");
});

it('lets a provider register module providers and contracts (§2a control)', function () {
    Rules::compositionRootOnlyRegisters('Tests\Arch\Fixtures\RootAllowed');
});

it('keeps the legacy allowlist closed (C2)', function () {
    Rules::legacyAllowlistIsClosed(dirname(__DIR__, 2).'/app');
});

it('rejects a new legacy model and controller, and the exited User (C2 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::legacyAllowlistIsClosed(__DIR__.'/Fixtures/C2'));

    expect($failure->getMessage())->toContain('Models/Vendor.php')
        ->toContain('Http/Controllers/CartController.php')
        // User left the allowlist when it moved to Identity\Domain (#33).
        ->toContain('Models/User.php')
        ->not->toContain('Http/Controllers/Controller.php');
});

it('has removed App\Models\User (ADR-0010 §2b exit, #33)', function () {
    expect(class_exists('App\Models\User'))->toBeFalse()
        ->and(is_dir(dirname(__DIR__, 2).'/app/Models'))->toBeFalse();
});

it('keeps the composition root off Identity\Domain\User (ADR-0010 §2a)', function () {
    // compositionRootOnlyRegisters covers every module's Domain; this names
    // the class that moved, so a regression points at it.
    expect('App\Providers')->not->toUse('App\Modules\Identity\Domain\User');
    expect('App\Http')->not->toUse('App\Modules\Identity\Domain\User');
});

it('keeps the module tree canonical (C3)', function () {
    Rules::moduleTreeIsCanonical(dirname(__DIR__, 2).'/app/Modules');
});

it('rejects an unknown module, an unknown sub-namespace, a stray class and an empty directory (C3 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::moduleTreeIsCanonical(__DIR__.'/Fixtures/C3/Modules'));

    expect($failure->getMessage())->toContain('Billing is not a module')
        ->toContain('Vendors/Services is not a sub-namespace')
        ->toContain('Vendors/VendorsModule.php is not a sub-namespace')
        ->toContain('Catalogue/Contracts has no class');
});
