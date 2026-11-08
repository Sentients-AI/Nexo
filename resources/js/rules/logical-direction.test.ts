// Dependency rule F4 / NFR-I18N-02: no physical left/right styling in the
// application; use logical equivalents so RTL works without overrides
// (ms-/me- for ml-/mr-, ps-/pe-, start-/end-, text-start/text-end,
// border-s/border-e, rounded-s/rounded-e, margin-inline-start, …).
//
// docs/architecture/dependency-rules.md plans this as an ESLint rule. ESLint
// is not installed (owner decision), so the rule runs here, in Vitest, until
// it is. A line containing `f4-allow` is skipped; give the reason on it.

import { describe, expect, it } from 'vitest';

const markup = import.meta.glob<string>(
    ['../**/*.vue', '../**/*.ts', '!../**/*.test.ts', '../../views/**/*.blade.php'],
    { eager: true, query: '?raw', import: 'default' },
);

const styles = import.meta.glob<string>(['../../css/**/*.css', '../**/*.vue'], {
    eager: true,
    query: '?raw',
    import: 'default',
});

// Tailwind utilities, with or without variants (md:, hover:) or a leading `-`.
const PHYSICAL_UTILITY = new RegExp(
    String.raw`(?<![\w-])-?(?:` +
        String.raw`(?:m|p|scroll-m|scroll-p)[lr]-[\w./\[\]-]+` +
        String.raw`|(?:left|right)-[\w./\[\]-]+` +
        String.raw`|(?:border|rounded)-(?:l|r|tl|tr|bl|br)(?:-[\w./\[\]-]+)?` +
        String.raw`|(?:text|float|clear)-(?:left|right)` +
        String.raw`)(?![\w-])`,
    'g',
);

// Physical CSS properties and values.
const PHYSICAL_CSS =
    /\b(?:margin|padding|border|inset|scroll-margin|scroll-padding)-(?:left|right)\b|(?<![\w-])(?:left|right)\s*:|\b(?:text-align|float|clear)\s*:\s*(?:left|right)\b/g;

function violations(files: Record<string, string>, pattern: RegExp, only?: (source: string) => string) {
    return Object.entries(files).flatMap(([path, source]) =>
        (only ? only(source) : source)
            .split('\n')
            .flatMap((line, index) =>
                line.includes('f4-allow')
                    ? []
                    : [...line.matchAll(pattern)].map((m) => `${path}:${index + 1} ${m[0].trim()}`),
            ),
    );
}

const styleBlocks = (source: string) =>
    source.includes('<style') ? source.replace(/[\s\S]*?(<style[\s\S]*?<\/style>)|[\s\S]+$/g, '$1') : source;

describe('dependency rule F4: logical direction only', () => {
    it('finds the files it is meant to scan', () => {
        expect(Object.keys(markup).some((path) => path.endsWith('.blade.php'))).toBe(true);
        expect(Object.keys(markup).some((path) => path.endsWith('.vue'))).toBe(true);
        expect(Object.keys(styles).some((path) => path.endsWith('.css'))).toBe(true);
    });

    it('detects physical utilities and properties (self-check)', () => {
        const sample = {
            'x.vue': 'class="md:ml-4 -mr-2 pl-[3px] left-0 text-right rounded-tl-lg border-r"\nmargin-left: 1rem; left: 0; text-align: right',
        };

        expect(violations(sample, PHYSICAL_UTILITY).map((v) => v.split(' ')[1])).toEqual([
            'ml-4',
            '-mr-2',
            'pl-[3px]',
            'left-0',
            'text-right',
            'rounded-tl-lg',
            'border-r',
        ]);
        expect(violations(sample, PHYSICAL_CSS)).toHaveLength(3);
        expect(violations({ 'y.vue': 'class="ms-4 pe-2 start-0 text-start rounded-s-lg border-e bottom-0"' }, PHYSICAL_UTILITY)).toEqual([]);
    });

    it('uses no physical Tailwind utilities', () => {
        expect(violations(markup, PHYSICAL_UTILITY)).toEqual([]);
    });

    it('uses no physical CSS properties', () => {
        expect(violations(styles, PHYSICAL_CSS, styleBlocks)).toEqual([]);
    });
});
