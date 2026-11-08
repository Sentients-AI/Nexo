<?php

namespace App\Providers;

use App\Http\Localization\Locale;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The Arabic face is preloaded only on Arabic pages. Elsewhere its
        // @font-face stays available (unicode-range limits it to Arabic
        // glyphs), so Arabic names inside other locales still use it.
        Vite::usePreloadTagAttributes(
            fn (string $src, string $url): array|false => $src === 'fonts'
                && str_contains($url, 'ibm-plex-sans-arabic')
                && Locale::current() !== Locale::Arabic ? false : [],
        );
    }
}
