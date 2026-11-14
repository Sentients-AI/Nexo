<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap: abort the whole run on an unsafe database configuration
|--------------------------------------------------------------------------
|
| PHPUnit has applied phpunit.xml's <env> by the time this file runs. The
| test database guard (ADR-0009 §3a) is checked here once, before any test
| starts, so an unsafe configuration stops the run instead of failing each
| test. Tests\CreatesApplication enforces the same guard again for every
| application it creates.
|
*/

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Tests\Support\Database\GuardInput;
use Tests\Support\Database\TestDatabaseGuard;
use Tests\Support\Database\TestDatabaseGuardViolation;

require __DIR__.'/../vendor/autoload.php';

$basePath = dirname(__DIR__);

try {
    // G1, G2: nothing is loaded yet.
    TestDatabaseGuard::enforce(GuardInput::fromEnvironment($basePath));

    // G1-G5: load only the environment and configuration. No provider is
    // booted and no connection is opened.
    $app = require $basePath.'/bootstrap/app.php';
    $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);

    TestDatabaseGuard::enforce(GuardInput::fromApplication($app));

    $app->flush();
    unset($app);
} catch (TestDatabaseGuardViolation $violation) {
    fwrite(STDERR, PHP_EOL.$violation->getMessage().PHP_EOL);

    exit(1);
}
