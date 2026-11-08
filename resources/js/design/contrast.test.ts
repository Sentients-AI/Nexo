import { describe, expect, it } from 'vitest';
import { contrastRatio, relativeLuminance } from './contrast';

describe('relativeLuminance', () => {
    it('is 0 for black and 1 for white', () => {
        expect(relativeLuminance('#000000')).toBe(0);
        expect(relativeLuminance('#ffffff')).toBe(1);
    });

    it('accepts three-digit and upper-case hex', () => {
        expect(relativeLuminance('#FFF')).toBe(1);
        expect(relativeLuminance('#abc')).toBeCloseTo(relativeLuminance('#aabbcc'), 10);
    });

    it('rejects values that are not hex colours', () => {
        expect(() => relativeLuminance('rgb(0, 0, 0)')).toThrow(/hex colour/);
        expect(() => relativeLuminance('#12345')).toThrow(/hex colour/);
    });
});

describe('contrastRatio', () => {
    it('is 21:1 for black on white, in either order', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 10);
        expect(contrastRatio('#ffffff', '#000000')).toBeCloseTo(21, 10);
    });

    it('is 1:1 for identical colours', () => {
        expect(contrastRatio('#7d7b75', '#7d7b75')).toBe(1);
    });

    it('matches the WCAG 2.x reference value for #767676 on white', () => {
        // #767676 is the lightest grey that passes 4.5:1 on white.
        expect(contrastRatio('#767676', '#ffffff')).toBeCloseTo(4.54, 2);
        expect(contrastRatio('#777777', '#ffffff')).toBeLessThan(4.5);
    });
});
