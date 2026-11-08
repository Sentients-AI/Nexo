// Reads design tokens from resources/css/tokens.css so tests and the local
// token gallery work from the stylesheet itself rather than a copy of it.

export type TokenMap = ReadonlyMap<string, string>;

const DECLARATION = /(--[\w-]+)\s*:\s*([^;]+);/g;
const VAR_REFERENCE = /^var\(\s*(--[\w-]+)\s*\)$/;

/**
 * Collects the custom properties declared in top-level `:root { … }` blocks.
 * Blocks nested in at-rules (e.g. reduced-motion) and qualified selectors
 * (e.g. `:root:lang(ar)`) are overrides, not token definitions, and are skipped.
 */
export function readRootTokens(css: string): Map<string, string> {
    const source = css.replace(/\/\*[\s\S]*?\*\//g, '');
    const tokens = new Map<string, string>();
    let depth = 0;
    let selectorStart = 0;

    for (let i = 0; i < source.length; i++) {
        const char = source[i];

        if (char === '{') {
            const selector = source.slice(selectorStart, i).trim();
            const end = source.indexOf('}', i);

            if (depth === 0 && selector === ':root' && end !== -1) {
                for (const [, name, value] of source.slice(i + 1, end).matchAll(DECLARATION)) {
                    tokens.set(name as string, (value as string).trim());
                }
            }

            depth++;
            selectorStart = i + 1;
        } else if (char === '}') {
            depth--;
            selectorStart = i + 1;
        } else if (char === ';' && depth === 0) {
            selectorStart = i + 1;
        }
    }

    return tokens;
}

/** Follows `var(--token)` references until a concrete value is reached. */
export function resolveToken(tokens: TokenMap, name: string): string {
    const seen = new Set<string>();
    let current = name;

    for (;;) {
        if (seen.has(current)) {
            throw new Error(`Token reference cycle: ${[...seen, current].join(' → ')}`);
        }

        seen.add(current);

        const value = tokens.get(current);

        if (value === undefined) {
            throw new Error(`Unknown token ${current}${current === name ? '' : ` (referenced by ${name})`}`);
        }

        const reference = VAR_REFERENCE.exec(value);

        if (!reference) {
            return value;
        }

        current = reference[1] as string;
    }
}
