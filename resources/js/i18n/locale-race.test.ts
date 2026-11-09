// M3 review: a slow catalogue load must not overwrite a newer visit's locale.
// Arabic's catalogue is held back until the test releases it, so Malay
// finishes first; without the guard, Arabic would win when it arrives late.
import { afterEach, describe, expect, it, vi } from 'vitest';

const gate = vi.hoisted(() => {
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });

    return { held, release: () => release() };
});

vi.mock('./messages/ar/ui.json', async () => {
    await gate.held;

    return { default: { common: { loading: 'جارٍ التحميل' } } };
});

afterEach(() => {
    gate.release();
});

describe('activateLocale ordering', () => {
    it('keeps the most recent locale when an earlier load finishes last', async () => {
        const { activateLocale, useI18n } = await import('./index');
        const { locale } = useI18n();

        const arabic = activateLocale('ar');
        await activateLocale('ms');

        expect(locale.value).toBe('ms');

        gate.release();
        await arabic;

        expect(locale.value).toBe('ms');
        expect(document.documentElement.lang).toBe('ms');
        expect(document.documentElement.dir).toBe('ltr');
    });

    it('still resolves the superseded call with the locale it was asked for', async () => {
        const { activateLocale } = await import('./index');

        gate.release();

        await expect(activateLocale('ar')).resolves.toBe('ar');
    });
});
