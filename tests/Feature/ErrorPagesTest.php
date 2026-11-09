<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/** Headers of a client-side Inertia visit with the current asset version. */
function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

beforeEach(function () {
    // Production settings: CI copies .env.example, which turns debug on.
    config(['app.debug' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/_test/forbidden', fn () => abort(403));
        Route::get('/_test/expired', fn () => throw new TokenMismatchException);
        Route::get('/_test/unavailable', fn () => abort(503));
        Route::get('/_test/teapot', fn () => abort(418));
        Route::get('/_test/crash', fn () => throw new RuntimeException(
            'DB_PASSWORD=hunter2 in /var/www/nexo/app/Secret.php',
        ));
    });
});

it('renders HTTP errors as a translated Inertia page', function (string $url, int $status) {
    $this->get($url)
        ->assertStatus($status)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Errors/ErrorPage')
            ->where('status', $status)
            ->where('locale', 'en')
            ->where('direction', 'ltr'));
})->with([
    '403' => ['/_test/forbidden', 403],
    '404 (no route)' => ['/no-such-page', 404],
    '419' => ['/_test/expired', 419],
    '500' => ['/_test/crash', 500],
    '503' => ['/_test/unavailable', 503],
]);

it('renders a 404 in the locale of the URL prefix, even with no route', function (string $url, string $locale, string $direction) {
    $this->get($url)
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Errors/ErrorPage')
            ->where('locale', $locale)
            ->where('direction', $direction))
        ->assertSee("<html lang=\"{$locale}\" dir=\"{$direction}\">", escape: false);
})->with([
    ['/no-such-page', 'en', 'ltr'],
    ['/ms/no-such-page', 'ms', 'ltr'],
    ['/ar/no-such-page', 'ar', 'rtl'],
    ['/arabic', 'en', 'ltr'],
]);

it('never exposes the exception with APP_DEBUG=false (NFR-SEC-07)', function (bool $inertia) {
    $headers = $inertia ? inertiaHeaders() : [];
    $body = $this->get('/_test/crash', $headers)->assertStatus(500)->getContent();

    expect($body)
        ->not->toContain('hunter2')
        ->not->toContain('DB_PASSWORD')
        ->not->toContain('Secret.php')
        ->not->toContain('RuntimeException')
        ->not->toContain(base_path())
        ->not->toContain(str_replace('\\', '\\\\', base_path()))
        ->not->toContain('Stack trace')
        ->not->toMatch('/#\d+ [A-Za-z\/\\\\]/');
})->with([
    'full page load' => [false],
    'Inertia visit' => [true],
]);

it('keeps the debug page for developers when APP_DEBUG is on', function () {
    config(['app.debug' => true]);

    $this->get('/_test/crash')
        ->assertStatus(500)
        ->assertSee('DB_PASSWORD=hunter2')
        ->assertHeaderMissing('X-Inertia');
});

it('answers Inertia visits with the error page object', function () {
    $this->get('/ar/no-such-page', inertiaHeaders())
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Errors/ErrorPage')
        ->assertJsonPath('props.status', 404)
        ->assertJsonPath('props.locale', 'ar');
});

it('keeps JSON errors for API and JSON clients', function () {
    $this->getJson('/no-such-page')
        ->assertNotFound()
        ->assertHeaderMissing('X-Inertia')
        ->assertJsonStructure(['message']);
});

it('shows the maintenance page in maintenance mode', function () {
    app()->maintenanceMode()->activate([]);

    try {
        $this->get('/')
            ->assertStatus(503)
            ->assertInertia(fn (Assert $page) => $page->component('Errors/ErrorPage')->where('status', 503))
            ->assertHeader('Content-Security-Policy');
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});

it('leaves other statuses to the framework', function () {
    $response = $this->get('/_test/teapot')->assertStatus(418);

    expect($response->getContent())->not->toContain('Errors\/ErrorPage');
});
