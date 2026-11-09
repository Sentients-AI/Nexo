<?php

/*
 * Development-only routes. bootstrap/app.php loads this file only when
 * APP_ENV=local, so none of these exist in testing, staging, demo or
 * production (they 404 like any unknown URL).
 */

use App\Http\Localization\LocalizedRoutes;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/dev/tokens', fn () => Inertia::render('Dev/Tokens'))->name('dev.tokens');
Route::get('/dev/components', fn () => Inertia::render('Dev/Components'))->name('dev.components');

// Layout previews, in every locale (/dev/layouts/…, /ms/dev/layouts/…,
// /ar/dev/layouts/…) so the language switcher and RTL can be checked. The
// checkout and vendor pages that will use these layouts need ADR-0011 (M6).
LocalizedRoutes::group(function () {
    Route::get('dev/layouts/checkout', fn () => Inertia::render('Storefront/CheckoutPreview'))->name('dev.layouts.checkout');
    Route::get('dev/layouts/vendor', fn () => Inertia::render('Vendor/LayoutPreview'))->name('dev.layouts.vendor');
}, redirectEnglishPrefix: false);
