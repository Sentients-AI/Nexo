<?php

/*
 * Development-only routes. bootstrap/app.php loads this file only when
 * APP_ENV=local, so none of these exist in testing, staging, demo or
 * production (they 404 like any unknown URL).
 */

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/dev/tokens', fn () => Inertia::render('Dev/Tokens'))->name('dev.tokens');
Route::get('/dev/components', fn () => Inertia::render('Dev/Components'))->name('dev.components');
