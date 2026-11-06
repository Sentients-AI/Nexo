<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Locales written right to left.
     *
     * @var list<string>
     */
    private const array RTL_LOCALES = ['ar'];

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
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'locale' => $locale,
            'direction' => in_array($locale, self::RTL_LOCALES, true) ? 'rtl' : 'ltr',
        ];
    }
}
