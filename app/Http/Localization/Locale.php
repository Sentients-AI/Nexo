<?php

namespace App\Http\Localization;

/**
 * Supported locales (ADR-0007). English is primary and the fallback, and is
 * served without a URL prefix (NFR-I18N-04).
 *
 * Mirrored by resources/js/i18n/locales.ts.
 */
enum Locale: string
{
    case English = 'en';
    case Malay = 'ms';
    case Arabic = 'ar';

    public static function fallback(): self
    {
        return self::English;
    }

    public static function current(): self
    {
        return self::tryFrom(app()->getLocale()) ?? self::fallback();
    }

    /** The locale whose prefix starts `$path` (e.g. "ar/products"), else the fallback. */
    public static function fromPath(string $path): self
    {
        $segment = explode('/', trim($path, '/'), 2)[0];

        foreach (self::cases() as $locale) {
            if ($locale->prefix() !== '' && $locale->prefix() === $segment) {
                return $locale;
            }
        }

        return self::fallback();
    }

    public function direction(): string
    {
        return $this === self::Arabic ? 'rtl' : 'ltr';
    }

    /** The URL prefix, or an empty string for the unprefixed fallback locale. */
    public function prefix(): string
    {
        return $this === self::fallback() ? '' : $this->value;
    }
}
