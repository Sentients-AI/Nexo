<!DOCTYPE html>
<html lang="{{ $page['props']['locale'] }}" dir="{{ $page['props']['direction'] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- The CSP nonce, for styles that Vite (dev) and Inertia inject at runtime. Browsers hide a nonce attribute's value from page CSS, so it cannot leak through selectors. --}}
        <meta property="csp-nonce" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">

        <title>{{ config('app.name', 'Nexo') }}</title>
{{-- Same markup and data-inertia keys as hreflangTags() in resources/js/i18n/alternates.ts, so Inertia's head manager updates these on every visit. --}}
@foreach ($page['props']['alternates'] ?? [] as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}" data-inertia="hreflang-{{ $hreflang }}">
@endforeach

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @includeWhen(app()->isLocal(), 'partials.dev-bar')
        @inertia
    </body>
</html>
