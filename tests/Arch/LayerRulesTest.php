<?php

use Tests\Arch\Support\Fixture;
use Tests\Arch\Support\Rules;

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
