// NFR-I18N-01: en, ms and ar have 100 % key coverage, and CI fails on a
// missing key. Runs in the `Node 24 / Vue 3` job with the rest of Vitest.

import { describe, expect, it } from 'vitest';
import { intlTag, LOCALES, type Locale } from './locales';
import { flattenMessages, isPluralMessage, type Catalogue, type Message } from './translate';

const files = import.meta.glob<{ default: object }>('./messages/*/*.json', { eager: true });

const catalogues = Object.fromEntries(
    LOCALES.map((locale) => {
        const merged: Record<string, Message> = {};

        for (const [path, module] of Object.entries(files)) {
            if (!path.startsWith(`./messages/${locale}/`)) continue;

            const namespace = path.slice(path.lastIndexOf('/') + 1, -'.json'.length);
            Object.assign(merged, flattenMessages({ [namespace]: module.default }));
        }

        return [locale, merged];
    }),
) as Record<Locale, Catalogue>;

// Application source, to find every t('literal.key') call.
const sources = import.meta.glob<string>(['../**/*.vue', '../**/*.ts', '!../**/*.test.ts', '!../i18n/**'], {
    eager: true,
    query: '?raw',
    import: 'default',
});

const placeholders = (message: Message): string[] =>
    [...(typeof message === 'string' ? message : message.other).matchAll(/\{(\w+)\}/g)]
        .map((match) => match[1] as string)
        .sort();

describe('translation coverage (NFR-I18N-01)', () => {
    const englishKeys = Object.keys(catalogues.en).sort();

    it('has English messages', () => {
        expect(englishKeys.length).toBeGreaterThan(0);
    });

    it.each(LOCALES.filter((l) => l !== 'en'))('%s has exactly the English keys', (locale) => {
        const keys = Object.keys(catalogues[locale]);

        expect({
            missing: englishKeys.filter((key) => !keys.includes(key)),
            extra: keys.filter((key) => !englishKeys.includes(key)).sort(),
        }).toEqual({ missing: [], extra: [] });
    });

    it.each(LOCALES)('%s plural messages define every plural category the language uses', (locale) => {
        const required = new Intl.PluralRules(intlTag(locale)).resolvedOptions().pluralCategories;
        const gaps = Object.entries(catalogues[locale]).flatMap(([key, message]) => {
            if (catalogues.en[key] !== undefined && isPluralMessage(catalogues.en[key]) !== isPluralMessage(message)) {
                return [`${key}: plural in one locale but not the other`];
            }

            return isPluralMessage(message)
                ? required.filter((category) => !(category in message)).map((category) => `${key}.${category}`)
                : [];
        });

        expect(gaps).toEqual([]);
    });

    it.each(LOCALES.filter((l) => l !== 'en'))('%s uses the same placeholders as English', (locale) => {
        const mismatched = englishKeys.filter((key) => {
            const translated = catalogues[locale][key];

            return translated !== undefined && placeholders(translated).join() !== placeholders(catalogues.en[key]!).join();
        });

        expect(mismatched).toEqual([]);
    });

    it('defines every key the application source asks for', () => {
        const used = new Set<string>();

        for (const source of Object.values(sources)) {
            for (const match of source.matchAll(/\bt\(\s*['"`]([\w.-]+)['"`]/g)) used.add(match[1] as string);
        }

        expect(used.size).toBeGreaterThan(0);
        expect([...used].filter((key) => catalogues.en[key] === undefined).sort()).toEqual([]);
    });
});
