<?php

use Tests\Arch\Support\Fixture;
use Tests\Arch\Support\Rules;

it('keeps the domain free of HTTP, UI, admin and provider code (L1)', function () {
    Rules::domainIsFrameworkFree('App\Modules');
});

it('rejects an HTTP request in the domain (L1 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::domainIsFrameworkFree('Tests\Arch\Fixtures\L1\Modules'));

    expect($failure->getMessage())->toContain("not to use 'Illuminate\\Http'");
});

it('keeps use cases free of the delivery layers (L2)', function () {
    Rules::applicationIgnoresDelivery('App\Modules');
});

it('rejects a use case that depends on its Http layer (L2 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::applicationIgnoresDelivery('Tests\Arch\Fixtures\L2\Modules'));

    expect($failure->getMessage())->toContain("not to use 'Tests\\Arch\\Fixtures\\L2\\Modules\\Catalogue\\Http'");
});

it('keeps the DB facade out of controllers (L3)', function () {
    Rules::controllersAvoidTheDatabase('App\Modules');
});

it('rejects the DB facade in a controller (L3 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::controllersAvoidTheDatabase('Tests\Arch\Fixtures\L3\Modules'));

    expect($failure->getMessage())->toContain("not to use 'Illuminate\\Support\\Facades\\DB'");
});

it('uses the provider SDK only in Payments\Infrastructure (L5)', function () {
    Rules::providerSdkOnlyInPayments(Rules::APPLICATION, 'App\Modules');
});

const STAND_IN_SDK = 'Tests\Arch\Fixtures\Stubs\ProviderSdk';

it('rejects the provider SDK outside Payments\Infrastructure (L5 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::providerSdkOnlyInPayments(
        ['Tests\Arch\Fixtures\L5\Modules'], 'Tests\Arch\Fixtures\L5\Modules', STAND_IN_SDK,
    ));

    expect($failure->getMessage())->toContain("not to use '".STAND_IN_SDK."'");
});

it('accepts the provider SDK inside Payments\Infrastructure (L5 control)', function () {
    Rules::providerSdkOnlyInPayments(['Tests\Arch\Fixtures\L5Allowed\Modules'], 'Tests\Arch\Fixtures\L5Allowed\Modules', STAND_IN_SDK);
});

it('reads env() only in config (L6)', function () {
    Rules::envOnlyInConfig(Rules::APPLICATION);
});

it('rejects env() outside config (L6 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::envOnlyInConfig(['Tests\Arch\Fixtures\L6']));

    expect($failure->getMessage())->toContain("not to use 'env'");
});

it('uses no debug helpers (L7)', function () {
    Rules::noDebugHelpers(Rules::APPLICATION);
});

it('rejects a debug helper (L7 fixture)', function () {
    $failure = Fixture::rejects(fn () => Rules::noDebugHelpers(['Tests\Arch\Fixtures\L7']));

    expect($failure->getMessage())->toContain('dump');
});
