// Which colour tokens must be legible against which, and how strongly.
// Rules are derived from token names, so a new token is checked automatically
// or the coverage test fails until it is classified (NFR-A11Y-03).

import { contrastRatio } from './contrast';
import { resolveToken, type TokenMap } from './tokens';

/** WCAG 1.4.3 (text) and 1.4.11 (non-text UI such as control borders and focus rings). */
export const TEXT_MINIMUM = 4.5;
export const NON_TEXT_MINIMUM = 3;

export const STATUS_TONES = ['success', 'warning', 'danger', 'info'] as const;

/** Tokens exempt from contrast rules, with the reason. */
export const DECORATIVE_COLOR_TOKENS: Readonly<Record<string, string>> = {
    '--nx-color-border-default': 'Dividers and card outlines; decorative (WCAG 1.4.11 exempts them).',
    '--nx-color-border-subtle': 'Hairlines inside already-bounded surfaces; decorative.',
};

export interface ContrastPair {
    foreground: string;
    background: string;
    minimum: number;
}

export interface ContrastResult extends ContrastPair {
    ratio: number;
    passes: boolean;
}

export function colorTokenNames(tokens: TokenMap): string[] {
    return [...tokens.keys()].filter((name) => name.startsWith('--nx-color-'));
}

export function contrastPairs(tokens: TokenMap): ContrastPair[] {
    const names = colorTokenNames(tokens);
    const backgrounds = names.filter((n) => n.startsWith('--nx-color-bg-'));
    const texts = names.filter((n) => n.startsWith('--nx-color-fg-'));
    const pairs: ContrastPair[] = [];
    const on = (foreground: string, background: string, minimum: number) =>
        pairs.push({ foreground, background, minimum });

    for (const fg of texts) {
        for (const bg of backgrounds) on(fg, bg, TEXT_MINIMUM);
    }

    for (const tone of STATUS_TONES) {
        const fg = `--nx-color-${tone}-fg`;

        on(fg, `--nx-color-${tone}-bg`, TEXT_MINIMUM);
        for (const bg of backgrounds) on(fg, bg, TEXT_MINIMUM);
    }

    on('--nx-color-on-accent', '--nx-color-accent', TEXT_MINIMUM);
    on('--nx-color-on-accent', '--nx-color-accent-hover', TEXT_MINIMUM);

    for (const ui of ['--nx-color-accent', '--nx-color-border-control', '--nx-color-focus-ring']) {
        for (const bg of backgrounds) on(ui, bg, NON_TEXT_MINIMUM);
    }

    return pairs;
}

export function evaluateContrast(tokens: TokenMap): ContrastResult[] {
    return contrastPairs(tokens).map((pair) => {
        const ratio = contrastRatio(resolveToken(tokens, pair.foreground), resolveToken(tokens, pair.background));

        return { ...pair, ratio, passes: ratio >= pair.minimum };
    });
}
