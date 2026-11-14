<?php

declare(strict_types=1);

/*
 * Not part of any test suite. tests/Platform/GuardAbortsBeforeSchemaTest.php
 * runs this file in a child process with a deliberately unsafe environment.
 * RefreshDatabase would run migrate:fresh on the configured database, so an
 * empty probe database afterwards proves the guard stopped the run first.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('migrates its database', function (): void {
    expect(true)->toBeTrue();
});
