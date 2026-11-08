// Locale-aware display formatting through Intl (NFR-I18N-03).
// Display only: no money arithmetic happens here (dependency rule F3).

import type { Money } from '@/types/money';
import { intlTag, TIME_ZONE, type Locale } from './locales';

// Directional marks ICU may insert, and the no-break spaces it uses.
const BIDI_MARKS = /[‎‏؜]/g;
const NO_BREAK_SPACES = /[   ]/g;

// ISO 8601 with a time and an explicit offset or Z, as ADR-0018 requires.
const ISO_WITH_OFFSET = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/;

const DATE: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' };
const DATE_TIME: Intl.DateTimeFormatOptions = { ...DATE, hour: 'numeric', minute: '2-digit' };

/**
 * Money follows the Malaysian convention in every locale ("RM 2,418.50"):
 * Latin digits, symbol first, no bidi marks. Components render it in an
 * LTR-isolated element so it reads the same inside Arabic text.
 */
export function formatMoney(money: Money, _locale: Locale): string {
    if (!Number.isInteger(money.amount)) {
        throw new Error(`Money must be integer minor units, got ${money.amount}`);
    }

    const format = new Intl.NumberFormat('en-MY', {
        style: 'currency',
        currency: money.currency,
        currencyDisplay: 'narrowSymbol',
    });
    const digits = format.resolvedOptions().maximumFractionDigits ?? 2;

    return format
        .format(money.amount / 10 ** digits)
        .replace(BIDI_MARKS, '')
        .replace(NO_BREAK_SPACES, ' ');
}

export function formatNumber(value: number, locale: Locale, options?: Intl.NumberFormatOptions): string {
    return new Intl.NumberFormat(intlTag(locale), options).format(value);
}

export function formatDate(iso: string, locale: Locale): string {
    return formatInstant(iso, locale, DATE);
}

export function formatDateTime(iso: string, locale: Locale): string {
    return formatInstant(iso, locale, DATE_TIME);
}

function formatInstant(iso: string, locale: Locale, options: Intl.DateTimeFormatOptions): string {
    const instant = new Date(iso);

    if (!ISO_WITH_OFFSET.test(iso) || Number.isNaN(instant.getTime())) {
        throw new Error(`Expected an ISO 8601 timestamp with an offset, got "${iso}"`);
    }

    return new Intl.DateTimeFormat(intlTag(locale), { ...options, timeZone: TIME_ZONE }).format(instant);
}
