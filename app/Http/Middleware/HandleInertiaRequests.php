<?php

namespace App\Http\Middleware;

use App\Http\Localization\Locale;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * Keep in sync with resources/js/types/shared.ts until ADR-0018
     * replaces it with generated types.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Closures: this middleware runs before the route's `locale:`
        // middleware, so the locale is read when the response is built.
        return [
            ...parent::share($request),
            'locale' => fn (): string => Locale::current()->value,
            'direction' => fn (): string => Locale::current()->direction(),
        ];
    }
}
