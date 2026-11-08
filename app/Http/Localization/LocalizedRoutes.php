<?php

namespace App\Http\Localization;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Registers storefront routes once per locale: English unprefixed, the
 * others under /{locale} (NFR-I18N-04). Each copy is named "{locale}.{name}"
 * so its alternates can be found and linked with hreflang.
 */
final class LocalizedRoutes
{
    public static function group(Closure $routes): void
    {
        // /en/... is never canonical: send it to the unprefixed URL. Registered
        // first so a later catch-all storefront route cannot shadow it.
        Route::get('en/{path?}', function (Request $request, ?string $path = null) {
            $query = $request->getQueryString();

            return redirect('/'.($path ?? '').($query === null ? '' : '?'.$query), 301);
        })->where('path', '.*');

        foreach (Locale::cases() as $locale) {
            Route::middleware('locale:'.$locale->value)
                ->prefix($locale->prefix())
                ->name($locale->value.'.')
                ->group($routes);
        }
    }

    /**
     * URLs of the current page in every locale, plus x-default (English).
     * Empty for routes that are not localised.
     *
     * @return array<string, string>
     */
    public static function alternates(?Request $request = null): array
    {
        $route = ($request ?? request())->route();
        $name = $route?->getName();

        if ($route === null || $name === null || ! preg_match('/^(en|ms|ar)\.(.+)$/', $name, $matches)) {
            return [];
        }

        $alternates = [];

        foreach (Locale::cases() as $locale) {
            if (Route::has($locale->value.'.'.$matches[2])) {
                $alternates[$locale->value] = route($locale->value.'.'.$matches[2], $route->parameters());
            }
        }

        if (isset($alternates[Locale::fallback()->value])) {
            $alternates['x-default'] = $alternates[Locale::fallback()->value];
        }

        return $alternates;
    }
}
