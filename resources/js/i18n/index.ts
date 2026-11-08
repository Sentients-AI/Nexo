// Active-locale state for the Vue app.
//
// Catalogues live in ./messages/<locale>/<namespace>.json and are code-split
// per locale: a page loads only its own locale plus English (the fallback).
// app.ts awaits activateLocale() while resolving each Inertia page, so the
// page never renders before its strings are loaded.

import { computed, shallowRef } from 'vue';
import type { Money } from '@/types/money';
import * as format from './format';
import { directionOf, FALLBACK_LOCALE, isLocale, type Locale } from './locales';
import { flattenMessages, translate, type Catalogue, type Params } from './translate';

export * from './locales';
export type { Params } from './translate';

type MessageModule = { default: object };

const loaders = import.meta.glob<MessageModule>('./messages/*/*.json');
const catalogues = new Map<Locale, Promise<Catalogue>>();

function loadCatalogue(locale: Locale): Promise<Catalogue> {
    let pending = catalogues.get(locale);

    if (!pending) {
        const files = Object.entries(loaders).filter(([path]) => path.startsWith(`./messages/${locale}/`));

        pending = Promise.all(
            files.map(async ([path, load]) => {
                const namespace = path.slice(path.lastIndexOf('/') + 1, -'.json'.length);

                return flattenMessages({ [namespace]: (await load()).default });
            }),
        ).then((parts) => Object.freeze(Object.assign({}, ...parts) as Catalogue));
        catalogues.set(locale, pending);
    }

    return pending;
}

interface ActiveState {
    locale: Locale;
    catalogue: Catalogue;
    fallback: Catalogue;
}

const active = shallowRef<ActiveState>({ locale: FALLBACK_LOCALE, catalogue: {}, fallback: {} });

/** Loads `locale` (falling back to English for unknown values) and makes it active. */
export async function activateLocale(requested: unknown): Promise<Locale> {
    const locale = isLocale(requested) ? requested : FALLBACK_LOCALE;
    const [catalogue, fallback] = await Promise.all([loadCatalogue(locale), loadCatalogue(FALLBACK_LOCALE)]);

    active.value = { locale, catalogue, fallback };

    if (typeof document !== 'undefined') {
        // The server sets these on the first response; keep them right after
        // client-side visits between locales.
        document.documentElement.lang = locale;
        document.documentElement.dir = directionOf(locale);
    }

    return locale;
}

export function useI18n() {
    const locale = computed(() => active.value.locale);

    return {
        locale,
        direction: computed(() => directionOf(active.value.locale)),
        t: (key: string, params?: Params): string => {
            const { locale: current, catalogue, fallback } = active.value;

            return translate(current, catalogue, fallback, key, params);
        },
        formatMoney: (money: Money) => format.formatMoney(money, locale.value),
        formatNumber: (value: number, options?: Intl.NumberFormatOptions) =>
            format.formatNumber(value, locale.value, options),
        formatDate: (iso: string) => format.formatDate(iso, locale.value),
        formatDateTime: (iso: string) => format.formatDateTime(iso, locale.value),
    };
}
