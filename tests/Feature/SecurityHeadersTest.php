<?php

use Illuminate\Support\Facades\Vite;

/** @return array<string, string> directive => value */
function cspDirectives(string $header): array
{
    $directives = [];

    foreach (array_filter(array_map('trim', explode(';', $header))) as $directive) {
        [$name, $value] = array_pad(explode(' ', $directive, 2), 2, '');
        $directives[$name] = $value;
    }

    return $directives;
}

it('sends the security headers (NFR-SEC-08)', function (string $url, int $status) {
    $this->get($url)
        ->assertStatus($status)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('Content-Security-Policy');
})->with([
    'storefront page' => ['/', 200],
    'localised page' => ['/ar', 200],
    'unknown URL (no route)' => ['/no-such-page', 404],
    'health check' => ['/up', 200],
]);

it('sends a strict, nonce-based content security policy', function () {
    $csp = cspDirectives($this->get('/')->headers->get('Content-Security-Policy'));
    $nonce = Vite::cspNonce();

    expect($nonce)->toBeString()->not->toBeEmpty()
        ->and($csp)->toMatchArray([
            'default-src' => "'self'",
            'script-src' => "'self' 'nonce-{$nonce}'",
            'style-src' => "'self' 'nonce-{$nonce}'",
            'img-src' => "'self' data:",
            'font-src' => "'self'",
            'connect-src' => "'self'",
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'none'",
        ])
        ->and(implode(' ', $csp))->not->toContain('unsafe-inline')->not->toContain('unsafe-eval');
});

it('puts the policy nonce on every script and style tag the page renders', function () {
    $response = $this->get('/');
    $html = $response->getContent();
    preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $match);

    preg_match_all('/<(script|style)\b[^>]*>/', $html, $tags);
    $executable = array_filter($tags[0], fn (string $tag) => ! str_contains($tag, 'type="application/json"'));

    expect($executable)->not->toBeEmpty();

    foreach ($executable as $tag) {
        expect($tag)->toContain('nonce="'.$match[1].'"');
    }

    expect($html)->toContain('<meta property="csp-nonce" nonce="'.$match[1].'">');
});

it('uses a new nonce for every response', function () {
    $first = $this->get('/')->headers->get('Content-Security-Policy');
    $second = $this->get('/')->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

it('sends HSTS over HTTPS outside local development', function (string $environment) {
    app()['env'] = $environment;

    $this->get('https://localhost/')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
})->with(['demo', 'staging', 'production']);

it('never sends HSTS over plain HTTP or in local development', function (string $environment, string $url) {
    app()['env'] = $environment;

    $this->get($url)->assertHeaderMissing('Strict-Transport-Security');
})->with([
    'HTTP on demo' => ['demo', 'http://localhost/'],
    'HTTPS in local' => ['local', 'https://localhost/'],
]);

it('allows the Vite dev server only while it is running', function () {
    $hot = public_path('hot');
    file_put_contents($hot, 'http://[::1]:5173');

    try {
        $csp = cspDirectives($this->get('/')->headers->get('Content-Security-Policy'));
    } finally {
        unlink($hot);
    }

    expect($csp['script-src'])->toContain('http://[::1]:5173')
        ->and($csp['connect-src'])->toBe("'self' http://[::1]:5173 ws://[::1]:5173")
        ->and($csp['style-src'])->toBe("'self' 'unsafe-inline' http://[::1]:5173");
});
