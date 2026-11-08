import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime, formatMoney, formatNumber } from './format';

// 2026-11-08T17:30Z is 01:30 on 9 November in Kuala Lumpur (UTC+8): the
// date itself changes, which proves the MYT conversion.
const LATE_EVENING_UTC = '2026-11-08T17:30:00Z';

describe('formatMoney', () => {
    it.each([
        ['en', 'RM 2,418.50'],
        ['ms', 'RM 2,418.50'],
        ['ar', 'RM 2,418.50'],
    ] as const)('formats MYR sen in %s with Latin digits and no bidi marks', (locale, expected) => {
        expect(formatMoney({ amount: 241850, currency: 'MYR' }, locale)).toBe(expected);
    });

    it('formats zero, negative and large amounts', () => {
        expect(formatMoney({ amount: 0, currency: 'MYR' }, 'en')).toBe('RM 0.00');
        expect(formatMoney({ amount: -1999, currency: 'MYR' }, 'en')).toBe('-RM 19.99');
        expect(formatMoney({ amount: 123456789, currency: 'MYR' }, 'ar')).toBe('RM 1,234,567.89');
    });

    it('rejects non-integer minor units (money is integer sen, ADR-0013)', () => {
        expect(() => formatMoney({ amount: 10.5, currency: 'MYR' }, 'en')).toThrow(/integer minor units/);
    });
});

describe('formatNumber', () => {
    it.each([
        ['en', '1,234,567.891'],
        ['ms', '1,234,567.891'],
        ['ar', '1,234,567.891'],
    ] as const)('formats in %s with Latin digits', (locale, expected) => {
        expect(formatNumber(1234567.891, locale)).toBe(expected);
    });
});

describe('formatDate', () => {
    it.each([
        ['en', '9 Nov 2026'],
        ['ms', '9 Nov 2026'],
        ['ar', '9 نوفمبر 2026'],
    ] as const)('shows the MYT calendar date in %s', (locale, expected) => {
        expect(formatDate(LATE_EVENING_UTC, locale)).toBe(expected);
    });

    it('rejects strings that are not ISO 8601 with an offset (ADR-0018)', () => {
        expect(() => formatDate('2026-11-08 17:30:00', 'en')).toThrow(/ISO 8601/);
        expect(() => formatDate('not a date', 'en')).toThrow(/ISO 8601/);
    });
});

describe('formatDateTime', () => {
    it.each([
        ['en', '9 Nov 2026, 1:30 am'],
        ['ms', '9 Nov 2026, 1:30 PG'],
        ['ar', '9 نوفمبر 2026، 1:30 ص'],
    ] as const)('shows MYT date and time in %s', (locale, expected) => {
        expect(formatDateTime(LATE_EVENING_UTC, locale)).toBe(expected);
    });
});
