import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { activateLocale } from '@/i18n';
import Home from './Home.vue';

// Inertia's <Head> needs a running Inertia app; render it as a no-op here.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: defineComponent({ name: 'Head', render: () => null }),
}));

describe('Storefront/Home', () => {
    it('renders the storefront heading', async () => {
        await activateLocale('en');

        expect(mount(Home).get('h1').text()).toBe('Nexo');
    });

    it.each([
        ['en', 'The storefront is on its way.'],
        ['ms', 'Kedai dalam talian sedang disiapkan.'],
        ['ar', 'المتجر قيد الإعداد.'],
    ] as const)('renders its copy in %s', async (locale, tagline) => {
        await activateLocale(locale);

        expect(mount(Home).text()).toContain(tagline);
        expect(document.documentElement.dir).toBe(locale === 'ar' ? 'rtl' : 'ltr');
    });
});
