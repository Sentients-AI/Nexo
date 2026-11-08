import { describe, expect, it } from 'vitest';
import { flattenMessages, translate } from './translate';

const en = flattenMessages({
    cart: {
        title: 'Your cart',
        greeting: 'Hello, {name}',
        items: { one: '{count} item', other: '{count} items' },
    },
    only: { english: 'English only' },
});

const ar = flattenMessages({
    cart: {
        title: 'سلتك',
        items: {
            zero: 'لا عناصر',
            one: 'عنصر واحد',
            two: 'عنصران',
            few: '{count} عناصر',
            many: '{count} عنصرًا',
            other: '{count} عنصر',
        },
    },
});

describe('flattenMessages', () => {
    it('flattens nested groups into dotted keys and keeps plural objects whole', () => {
        expect(Object.keys(en).sort()).toEqual(['cart.greeting', 'cart.items', 'cart.title', 'only.english']);
        expect(en['cart.items']).toEqual({ one: '{count} item', other: '{count} items' });
    });
});

describe('translate', () => {
    it('returns the message for the active locale', () => {
        expect(translate('ar', ar, en, 'cart.title')).toBe('سلتك');
    });

    it('interpolates named parameters and leaves unknown ones visible', () => {
        expect(translate('en', en, en, 'cart.greeting', { name: 'Aisyah' })).toBe('Hello, Aisyah');
        expect(translate('en', en, en, 'cart.greeting')).toBe('Hello, {name}');
    });

    it('selects the plural form with Intl.PluralRules for the locale', () => {
        expect(translate('en', en, en, 'cart.items', { count: 1 })).toBe('1 item');
        expect(translate('en', en, en, 'cart.items', { count: 3 })).toBe('3 items');
        expect(translate('ar', ar, en, 'cart.items', { count: 0 })).toBe('لا عناصر');
        expect(translate('ar', ar, en, 'cart.items', { count: 2 })).toBe('عنصران');
        expect(translate('ar', ar, en, 'cart.items', { count: 5 })).toBe('5 عناصر');
        expect(translate('ar', ar, en, 'cart.items', { count: 11 })).toBe('11 عنصرًا');
    });

    it('falls back to English, then to the key itself', () => {
        expect(translate('ar', ar, en, 'only.english')).toBe('English only');
        expect(translate('ar', ar, en, 'does.not.exist')).toBe('does.not.exist');
    });

    it('requires a count for plural messages', () => {
        expect(() => translate('en', en, en, 'cart.items')).toThrow(/count/);
    });
});
