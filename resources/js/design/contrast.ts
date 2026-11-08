// WCAG 2.x relative luminance and contrast ratio.
// https://www.w3.org/TR/WCAG22/#dfn-relative-luminance

const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

function channels(hex: string): [number, number, number] {
    if (!HEX.test(hex)) {
        throw new Error(`Expected a hex colour such as #1c1b19, got "${hex}"`);
    }

    const digits =
        hex.length === 4
            ? hex
                  .slice(1)
                  .split('')
                  .map((d) => d + d)
                  .join('')
            : hex.slice(1);

    return [0, 2, 4].map((i) => parseInt(digits.slice(i, i + 2), 16) / 255) as [number, number, number];
}

function linearise(channel: number): number {
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
}

export function relativeLuminance(hex: string): number {
    const [r, g, b] = channels(hex).map(linearise) as [number, number, number];

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(a: string, b: string): number {
    const [lighter, darker] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x) as [number, number];

    return (lighter + 0.05) / (darker + 0.05);
}
