// hreflang alternates (NFR-I18N-04). The server computes them per page and
// shares them as the `alternates` prop (LocalizedRoutes::alternates()).
//
// app.blade.php renders them on the first response so crawlers see them
// without JavaScript. Each tag carries a data-inertia key, and app.ts hands
// hreflangTags() to Inertia's `serverHead`, so the head manager replaces them
// on every visit instead of leaving the first page's links in the DOM.
import type { Locale } from './locales';

export type Hreflang = Locale | 'x-default';

/** Current page's URL per locale. PHP sends an empty array when there are none. */
export type Alternates = Partial<Record<Hreflang, string>>;

const escapeAttribute = (value: string) =>
    value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/**
 * Link tags for Inertia's `serverHead`. Takes the raw prop (untyped there)
 * and ignores anything that is not a string URL. Must match the markup in
 * resources/views/app.blade.php.
 */
export function hreflangTags(alternates: unknown): string[] {
    if (typeof alternates !== 'object' || alternates === null) return [];

    return Object.entries(alternates)
        .filter((entry): entry is [string, string] => typeof entry[1] === 'string')
        .map(
            ([hreflang, href]) =>
                `<link rel="alternate" hreflang="${escapeAttribute(hreflang)}" href="${escapeAttribute(href)}" data-inertia="hreflang-${escapeAttribute(hreflang)}">`,
        );
}
