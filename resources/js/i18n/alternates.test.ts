import { describe, expect, it } from 'vitest';
import { hreflangTags } from './alternates';

describe('hreflangTags', () => {
    it('renders one keyed link per alternate, in the server markup', () => {
        expect(hreflangTags({ en: 'https://nexo.test', ar: 'https://nexo.test/ar', 'x-default': 'https://nexo.test' })).toEqual([
            '<link rel="alternate" hreflang="en" href="https://nexo.test" data-inertia="hreflang-en">',
            '<link rel="alternate" hreflang="ar" href="https://nexo.test/ar" data-inertia="hreflang-ar">',
            '<link rel="alternate" hreflang="x-default" href="https://nexo.test" data-inertia="hreflang-x-default">',
        ]);
    });

    it('renders nothing for pages without alternates (PHP sends [])', () => {
        expect(hreflangTags(undefined)).toEqual([]);
        expect(hreflangTags([])).toEqual([]);
        expect(hreflangTags({ en: 42 })).toEqual([]);
    });

    it('escapes attribute values', () => {
        expect(hreflangTags({ en: 'https://nexo.test/?a=1&b="x"' })[0]).toContain(
            'href="https://nexo.test/?a=1&amp;b=&quot;x&quot;"',
        );
    });
});
