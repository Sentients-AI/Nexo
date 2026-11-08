<?php

use Inertia\Testing\AssertableInertia as Assert;

it('serves each locale under its URL prefix (NFR-I18N-04)', function (string $url, string $locale, string $direction) {
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Storefront/Home')
            ->where('locale', $locale)
            ->where('direction', $direction))
        ->assertSee("<html lang=\"{$locale}\" dir=\"{$direction}\">", escape: false);
})->with([
    'English, unprefixed' => ['/', 'en', 'ltr'],
    'Malay' => ['/ms', 'ms', 'ltr'],
    'Arabic' => ['/ar', 'ar', 'rtl'],
]);

it('does not carry a locale over to the next request', function () {
    $this->get('/ar')->assertInertia(fn (Assert $page) => $page->where('locale', 'ar'));

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('locale', 'en')->where('direction', 'ltr'));
});

it('redirects the English prefix to the unprefixed canonical URL', function (string $from, string $to) {
    $this->get($from)->assertStatus(301)->assertRedirect($to);
})->with([
    ['/en', '/'],
    ['/en/', '/'],
    ['/en/products/kopi?sort=new', '/products/kopi?sort=new'],
]);

it('only treats a whole /en segment as the English prefix', function () {
    $this->get('/enquiries')->assertNotFound();
});

it('does not serve unsupported locale prefixes', function (string $url) {
    $this->get($url)->assertNotFound();
})->with(['/fr', '/AR', '/zh/']);

it('lists hreflang alternates for every locale plus x-default', function (string $url) {
    $response = $this->get($url)->assertOk();

    foreach (['en' => '/', 'ms' => '/ms', 'ar' => '/ar', 'x-default' => '/'] as $hreflang => $path) {
        $response->assertSee(
            '<link rel="alternate" hreflang="'.$hreflang.'" href="'.url($path).'">',
            escape: false,
        );
    }
})->with(['/', '/ms', '/ar']);

// One request per test: Laravel's Vite service remembers which assets it
// has preloaded and skips them on later requests in the same application.
it('preloads the Arabic font on Arabic pages', function () {
    expect($this->get('/ar')->getContent())
        ->toMatch('/<link rel="preload" as="font" href="[^"]+ibm-plex-sans-arabic-400[^"]+\.woff2"/')
        ->toMatch('/<link rel="preload" as="font" href="[^"]+ibm-plex-sans-arabic-600[^"]+\.woff2"/');
});

it('does not preload the Arabic font on other pages, but keeps its font face', function (string $url) {
    $html = $this->get($url)->getContent();

    expect($html)
        ->toMatch('/<link rel="preload" as="font" href="[^"]+instrument-sans-400/')
        ->not->toMatch('/<link rel="preload"[^>]+ibm-plex-sans-arabic/')
        ->toContain('IBM Plex Sans Arabic');
})->with(['/', '/ms']);
