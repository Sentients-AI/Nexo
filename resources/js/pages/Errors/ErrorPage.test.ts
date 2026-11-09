import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { activateLocale, type Locale } from '@/i18n';
import { axeViolations } from '@/test/axe';
import ErrorPage from './ErrorPage.vue';

// Inertia's <Head> needs a running Inertia app; render it as a no-op here.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: defineComponent({ name: 'Head', render: () => null }),
}));

const mountAt = async (status: 403 | 404 | 419 | 500 | 503, locale: Locale = 'en') => {
    await activateLocale(locale);

    return mount(ErrorPage, { props: { status }, attachTo: document.body });
};

describe('Errors/ErrorPage', () => {
    it.each([
        [403, 'You can’t open this page'],
        [404, 'We can’t find that page'],
        [419, 'This page has expired'],
        [500, 'Something went wrong'],
        [503, 'We’ll be back soon'],
    ] as const)('explains a %i in plain words', async (status, heading) => {
        const page = await mountAt(status);

        expect(page.get('h1').text()).toBe(heading);
        expect(page.text()).toContain(`Error ${status}`);
    });

    it.each([
        ['en', '/', 'Go to the home page'],
        ['ms', '/ms', 'Pergi ke halaman utama'],
        ['ar', '/ar', 'الانتقال إلى الصفحة الرئيسية'],
    ] as const)('links to the %s home page', async (locale, href, label) => {
        const link = (await mountAt(404, locale)).get('main a');

        expect(link.attributes('href')).toBe(href);
        expect(link.text()).toBe(label);
    });

    it('offers no home link during maintenance, since home is down too', async () => {
        expect((await mountAt(503)).find('main a').exists()).toBe(false);
    });

    it('is translated in Malay and Arabic', async () => {
        expect((await mountAt(404, 'ms')).get('h1').text()).toBe('Halaman tidak dijumpai');
        expect((await mountAt(404, 'ar')).get('h1').text()).toBe('تعذّر العثور على هذه الصفحة');
    });

    it.each([403, 404, 419, 500, 503] as const)('has no axe violations (%i)', async (status) => {
        expect(await axeViolations((await mountAt(status, 'ar')).element)).toEqual([]);
    });
});
