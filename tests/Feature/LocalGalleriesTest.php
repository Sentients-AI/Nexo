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

it('is not routed outside the local environment', function (string $environment, string $url) {
    bootAs($environment);

    expect(app()->environment())->toBe($environment);

    $this->get($url)->assertNotFound();
})->with(['production', 'staging', 'demo', 'testing'])->with(['/dev/tokens', '/dev/components']);

it('renders the galleries in the local environment', function (string $url, string $component) {
    bootAs('local');

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'tokens' => ['/dev/tokens', 'Dev/Tokens'],
    'components' => ['/dev/components', 'Dev/Components'],
]);
