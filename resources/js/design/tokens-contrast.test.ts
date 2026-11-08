import tokensCss from '../../css/tokens.css?raw';
import { describe, expect, it } from 'vitest';
import { colorTokenNames, contrastPairs, DECORATIVE_COLOR_TOKENS, evaluateContrast } from './contrast-pairs';
import { readRootTokens } from './tokens';

const tokens = readRootTokens(tokensCss);

describe('design token contrast (NFR-A11Y-03)', () => {
    it('defines the semantic colour tokens the rules rely on', () => {
        expect(colorTokenNames(tokens)).toEqual(
            expect.arrayContaining([
                '--nx-color-bg-canvas',
                '--nx-color-bg-surface',
                '--nx-color-fg-default',
                '--nx-color-fg-muted',
                '--nx-color-accent',
                '--nx-color-accent-hover',
                '--nx-color-on-accent',
                '--nx-color-border-control',
                '--nx-color-focus-ring',
            ]),
        );
    });

    it.each(evaluateContrast(tokens).map((r) => [r.foreground, r.background, r] as const))(
        '%s on %s meets its minimum',
        (_fg, _bg, result) => {
            expect(result.ratio, `${result.ratio.toFixed(2)}:1 < ${result.minimum}:1`).toBeGreaterThanOrEqual(
                result.minimum,
            );
        },
    );

    it('classifies every colour token: checked by a pair, or explicitly decorative', () => {
        const checked = new Set(contrastPairs(tokens).flatMap((p) => [p.foreground, p.background]));
        const unclassified = colorTokenNames(tokens).filter(
            (name) => !checked.has(name) && !(name in DECORATIVE_COLOR_TOKENS),
        );

        expect(unclassified).toEqual([]);
    });

    it('lists only decorative tokens that exist', () => {
        expect(Object.keys(DECORATIVE_COLOR_TOKENS).filter((name) => !tokens.has(name))).toEqual([]);
    });
});
