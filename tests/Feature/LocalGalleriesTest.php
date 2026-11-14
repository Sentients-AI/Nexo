<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Boots a fresh application whose runtime environment is $environment, so the
 * environment gate in bootstrap/app.php runs for real. The process stays
 * APP_ENV=testing, so configuration is loaded and guarded as testing
 * (ADR-0009 §3a, G2).
 */
function bootAs(string $environment): void
{
    test()->rebootSimulatingEnvironment($environment);
}

it('is not routed outside the local environment', function (string $environment, string $url) {
    bootAs($environment);

    expect(app()->environment())->toBe($environment);

    $this->get($url)->assertNotFound();
})->with(['production', 'staging', 'demo', 'testing'])->with([
    '/dev/tokens',
    '/dev/components',
    '/dev/layouts/checkout',
    '/ar/dev/layouts/checkout',
    '/dev/layouts/vendor',
    '/ms/dev/layouts/vendor',
]);

it('shows a development navigation bar on every page in local', function (string $url) {
    bootAs('local');

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('<nav aria-label="Development" lang="en" dir="ltr"');

    foreach (['/', '/ms', '/ar', '/dev/tokens', '/dev/components', '/dev/layouts/checkout', '/dev/layouts/vendor'] as $path) {
        expect($html)->toContain('href="'.url($path).'"');
    }
})->with(['/', '/ar', '/dev/components']);

it('never shows the development navigation bar outside local', function (string $environment) {
    bootAs($environment);

    expect($this->get('/')->assertOk()->getContent())->not->toContain('aria-label="Development"');
})->with(['production', 'staging', 'demo', 'testing']);

it('renders the galleries in the local environment', function (string $url, string $component) {
    bootAs('local');

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'tokens' => ['/dev/tokens', 'Dev/Tokens'],
    'components' => ['/dev/components', 'Dev/Components'],
    'checkout layout' => ['/dev/layouts/checkout', 'Storefront/CheckoutPreview'],
    'vendor layout' => ['/dev/layouts/vendor', 'Vendor/LayoutPreview'],
]);

it('previews the layouts in every locale, with alternates for the switcher', function (string $prefix, string $locale, string $direction) {
    bootAs('local');

    $this->get($prefix.'/dev/layouts/vendor')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Vendor/LayoutPreview')
            ->where('locale', $locale)
            ->where('direction', $direction)
            ->where('alternates.ar', url('/ar/dev/layouts/vendor'))
            ->where('alternates.x-default', url('/dev/layouts/vendor')));
})->with([
    ['', 'en', 'ltr'],
    ['/ms', 'ms', 'ltr'],
    ['/ar', 'ar', 'rtl'],
]);

it('registers the /en redirect only once', function () {
    bootAs('local');

    $redirects = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->uri() === 'en/{path?}');

    expect($redirects)->toHaveCount(1);
});
