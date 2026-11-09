// Supported locales (ADR-0007). Mirrors App\Http\Localization\Locale.
export const LOCALES = ['en', 'ms', 'ar'] as const;

export type Locale = (typeof LOCALES)[number];
export type Direction = 'ltr' | 'rtl';

export const FALLBACK_LOCALE: Locale = 'en';

/** Displayed dates and times are always Malaysian time (NFR-I18N-03, ADR-0018). */
export const TIME_ZONE = 'Asia/Kuala_Lumpur';

// BCP 47 tags passed to Intl. Arabic keeps Latin digits (owner decision,
// ADR-0018 §3) through the -u-nu-latn extension.
const INTL_TAGS: Record<Locale, string> = {
    en: 'en-MY',
    ms: 'ms-MY',
    ar: 'ar-u-nu-latn',
};

export function isLocale(value: unknown): value is Locale {
    return typeof value === 'string' && (LOCALES as readonly string[]).includes(value);
}

export function directionOf(locale: Locale): Direction {
    return locale === 'ar' ? 'rtl' : 'ltr';
}

/** The storefront home URL for `locale`: "/" for English, "/ms" or "/ar" otherwise. */
export function homePath(locale: Locale): string {
    return locale === FALLBACK_LOCALE ? '/' : `/${locale}`;
}

export function intlTag(locale: Locale): string {
    return INTL_TAGS[locale];
}
