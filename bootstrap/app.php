<?php

use App\Http\Errors\ErrorPages;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (Application $app): void {
            if ($app->environment('local')) {
                Route::middleware('web')->group(base_path('routes/local.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['locale' => SetLocale::class]);

        // Global and first, so 404s, maintenance (503) responses and /up get
        // the headers too, and the CSP nonce exists before any page renders.
        $middleware->prepend(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 403, 404, 419, 500 and 503 render as translated Inertia pages.
        $exceptions->respond(new ErrorPages);
    })->create();
