// Dependency rule F1: storefront and vendor code may import shared code but
// not each other, and shared code imports neither.
//
// docs/architecture/dependency-rules.md plans this as ESLint
// `import/no-restricted-paths`. ESLint is not installed (owner decision), so
// the rule runs here until it is, like F4 (logical-direction.test.ts).
//
// Surfaces (paths relative to resources/js):
//   storefront: storefront/**, pages/Storefront/**
//   vendor:     vendor/**,     pages/Vendor/**
//   shared:     everything else (shared/**, i18n/**, design/**, types/**, …)

import { describe, expect, it } from 'vitest';

type Surface = 'storefront' | 'vendor' | 'shared';

const sources = import.meta.glob<string>(['../**/*.vue', '../**/*.ts', '!../**/*.test.ts', '!../test/**'], {
    eager: true,
    query: '?raw',
    import: 'default',
});

/** Path relative to resources/js, from a glob key such as "../pages/Storefront/Home.vue". */
const fromRoot = (globKey: string) => globKey.replace(/^\.\.\//, '');

export function surfaceOf(path: string): Surface {
    if (/^(storefront|pages\/Storefront)\//.test(path)) return 'storefront';
    if (/^(vendor|pages\/Vendor)\//.test(path)) return 'vendor';

    return 'shared';
}

/** Resolves an import specifier to a path relative to resources/js, or null for packages. */
export function resolveImport(fromPath: string, specifier: string): string | null {
    if (specifier.startsWith('@/')) return specifier.slice(2);
    if (!specifier.startsWith('.')) return null;

    const parts = fromPath.split('/').slice(0, -1);

    for (const segment of specifier.split('/')) {
        if (segment === '..') parts.pop();
        else if (segment !== '.') parts.push(segment);
    }

    return parts.join('/');
}

const IMPORT = /(?:\bfrom\s*|\bimport\s*\(?\s*)['"]([^'"]+)['"]/g;

const ALLOWED: Record<Surface, Surface[]> = {
    storefront: ['storefront', 'shared'],
    vendor: ['vendor', 'shared'],
    shared: ['shared'],
};

function violations(files: Record<string, string>): string[] {
    return Object.entries(files).flatMap(([key, source]) => {
        const path = fromRoot(key);
        const from = surfaceOf(path);

        return [...source.matchAll(IMPORT)].flatMap((match) => {
            const target = resolveImport(path, match[1] as string);

            if (target === null) return [];

            const to = surfaceOf(target);

            return ALLOWED[from].includes(to) ? [] : [`${path} (${from}) imports ${match[1]} (${to})`];
        });
    });
}

describe('dependency rule F1: surface boundaries', () => {
    it('classifies paths into surfaces', () => {
        expect(surfaceOf('pages/Storefront/Home.vue')).toBe('storefront');
        expect(surfaceOf('storefront/cart/useCart.ts')).toBe('storefront');
        expect(surfaceOf('pages/Vendor/Orders/Index.vue')).toBe('vendor');
        expect(surfaceOf('shared/ui/AppButton.vue')).toBe('shared');
        expect(surfaceOf('i18n/index.ts')).toBe('shared');
    });

    it('detects cross-surface imports (self-check)', () => {
        expect(
            violations({
                '../pages/Storefront/Cart.vue': `import X from '@/pages/Vendor/Orders.vue';\nimport Y from '@/shared/ui/AppButton.vue';`,
                '../vendor/useOrders.ts': `const m = await import('../storefront/cart');`,
                '../shared/ui/Thing.vue': `import { a } from '../../pages/Storefront/Home.vue';\nimport { ref } from 'vue';`,
            }),
        ).toEqual([
            'pages/Storefront/Cart.vue (storefront) imports @/pages/Vendor/Orders.vue (vendor)',
            'vendor/useOrders.ts (vendor) imports ../storefront/cart (storefront)',
            'shared/ui/Thing.vue (shared) imports ../../pages/Storefront/Home.vue (storefront)',
        ]);
    });

    it('finds the application source', () => {
        expect(Object.keys(sources).some((key) => key.includes('shared/ui/'))).toBe(true);
    });

    it('has no cross-surface imports', () => {
        expect(violations(sources)).toEqual([]);
    });
});
