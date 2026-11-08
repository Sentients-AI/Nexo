<?php

namespace App\Http\Middleware;

use App\Http\Localization\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the locale a route was registered for (see LocalizedRoutes).
 * Used as `locale:ms`.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next, string $locale): Response
    {
        app()->setLocale(Locale::from($locale)->value);

        return $next($request);
    }
}
