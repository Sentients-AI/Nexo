// Message lookup, interpolation and plural selection. Pure functions; the
// reactive wrapper lives in ./index.ts.

import { intlTag, type Locale } from './locales';

/** A plural message keyed by Intl.PluralRules category. `other` is required. */
export type PluralMessage = Partial<Record<Intl.LDMLPluralRule, string>> & { other: string };
export type Message = string | PluralMessage;

/** Flat catalogue: dotted key → message. */
export type Catalogue = Readonly<Record<string, Message>>;

export type Params = Readonly<Record<string, string | number>>;

const PLURAL_CATEGORIES = new Set<string>(['zero', 'one', 'two', 'few', 'many', 'other']);

export function isPluralMessage(value: unknown): value is PluralMessage {
    return (
        typeof value === 'object' &&
        value !== null &&
        'other' in value &&
        Object.keys(value).every((key) => PLURAL_CATEGORIES.has(key))
    );
}

/** Turns `{ cart: { title: '…' } }` into `{ 'cart.title': '…' }`; plural objects stay whole. */
export function flattenMessages(tree: object, prefix = ''): Record<string, Message> {
    const flat: Record<string, Message> = {};

    for (const [key, value] of Object.entries(tree)) {
        const path = prefix ? `${prefix}.${key}` : key;

        if (typeof value === 'string' || isPluralMessage(value)) {
            flat[path] = value;
        } else if (typeof value === 'object' && value !== null) {
            Object.assign(flat, flattenMessages(value, path));
        } else {
            throw new Error(`Message "${path}" must be a string, a plural object or a group`);
        }
    }

    return flat;
}

const pluralRules = new Map<Locale, Intl.PluralRules>();

function pluralCategory(locale: Locale, count: number): Intl.LDMLPluralRule {
    let rules = pluralRules.get(locale);

    if (!rules) {
        rules = new Intl.PluralRules(intlTag(locale));
        pluralRules.set(locale, rules);
    }

    return rules.select(count);
}

function interpolate(template: string, params: Params | undefined): string {
    return template.replace(/\{(\w+)\}/g, (placeholder, name: string) =>
        params && name in params ? String(params[name]) : placeholder,
    );
}

/**
 * Looks `key` up in the active catalogue, then the fallback catalogue.
 * A missing key renders as the key itself so it is visible, never blank;
 * the coverage test keeps that from reaching a release.
 */
export function translate(
    locale: Locale,
    catalogue: Catalogue,
    fallback: Catalogue,
    key: string,
    params?: Params,
): string {
    const found = catalogue[key] !== undefined;
    const message = found ? catalogue[key] : fallback[key];

    if (message === undefined) {
        return key;
    }

    if (typeof message === 'string') {
        return interpolate(message, params);
    }

    const count = params?.count;

    if (typeof count !== 'number') {
        throw new Error(`Plural message "${key}" needs a numeric "count" parameter`);
    }

    const rulesLocale = found ? locale : 'en';
    const form = message[pluralCategory(rulesLocale, count)] ?? message.other;

    return interpolate(form, params);
}
