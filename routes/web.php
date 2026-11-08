<?php

use App\Http\Localization\LocalizedRoutes;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Storefront: registered for every locale (/, /ms, /ar) and named
// "{locale}.storefront.*".
LocalizedRoutes::group(function () {
    Route::get('/', fn () => Inertia::render('Storefront/Home'))->name('storefront.home');
});
