<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Boots a fresh application as if APP_ENV were $environment, so the
 * environment gate in bootstrap/app.php runs for real.
 */
function bootAs(string $environment): void
{
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $environment;
    putenv("APP_ENV={$environment}");

    test()->refreshApplication();
}

afterEach(function () {
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
    putenv('APP_ENV=testing');
});

it('is not routed outside the local environment', function (string $environment) {
    bootAs($environment);

    expect(app()->environment())->toBe($environment);

    $this->get('/dev/tokens')->assertNotFound();
})->with(['production', 'staging', 'demo', 'testing']);

it('renders the token gallery in the local environment', function () {
    bootAs('local');

    $this->get('/dev/tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dev/Tokens'));
});
