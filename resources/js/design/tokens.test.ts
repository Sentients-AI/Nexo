import { describe, expect, it } from 'vitest';
import { readRootTokens, resolveToken } from './tokens';

const css = `
/* --nx-commented-out: #000; */
:root {
    --nx-palette-ink: #1c1b19;
    --nx-color-fg-default: var(--nx-palette-ink);
    --nx-color-fg-alias: var(--nx-color-fg-default);
    --nx-text-base: 1rem;
}

@media (prefers-reduced-motion: reduce) {
    :root {
        --nx-duration-base: 0ms;
    }
}

:root:lang(ar) {
    --nx-text-base: 1.0625rem;
}

:root {
    --nx-duration-base: 200ms;
}
`;

describe('readRootTokens', () => {
    it('reads custom properties from top-level :root blocks only', () => {
        const tokens = readRootTokens(css);

        expect([...tokens.keys()]).toEqual([
            '--nx-palette-ink',
            '--nx-color-fg-default',
            '--nx-color-fg-alias',
            '--nx-text-base',
            '--nx-duration-base',
        ]);
        expect(tokens.get('--nx-text-base')).toBe('1rem');
        expect(tokens.get('--nx-duration-base')).toBe('200ms');
    });

    it('ignores commented-out declarations', () => {
        expect(readRootTokens(css).has('--nx-commented-out')).toBe(false);
    });
});

describe('resolveToken', () => {
    it('follows var() references to the underlying value', () => {
        const tokens = readRootTokens(css);

        expect(resolveToken(tokens, '--nx-color-fg-alias')).toBe('#1c1b19');
        expect(resolveToken(tokens, '--nx-text-base')).toBe('1rem');
    });

    it('fails loudly on unknown tokens and reference cycles', () => {
        const cyclic = readRootTokens(':root { --a: var(--b); --b: var(--a); }');

        expect(() => resolveToken(readRootTokens(css), '--nx-missing')).toThrow(/Unknown token/);
        expect(() => resolveToken(cyclic, '--a')).toThrow(/cycle/);
    });
});
