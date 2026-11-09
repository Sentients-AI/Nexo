// Storefront, checkout and vendor layouts plus their shared pieces (skip
// link, language switcher). One file because they share the page stub.
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import { activateLocale, type Locale } from '@/i18n';
import type { Alternates } from '@/i18n/alternates';
import CheckoutLayout from '@/storefront/layouts/CheckoutLayout.vue';
import StorefrontLayout from '@/storefront/layouts/StorefrontLayout.vue';
import { axeViolations } from '@/test/axe';
import type { Flash } from '@/types/shared';
import VendorLayout from '@/vendor/layouts/VendorLayout.vue';
import LocaleSwitcher from './LocaleSwitcher.vue';

const page = reactive<{ props: { alternates: Alternates }; flash: Flash }>({ props: { alternates: {} }, flash: {} });

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => page,
}));

const HOME: Alternates = { en: '/', ms: '/ms', ar: '/ar', 'x-default': '/' };

beforeEach(() => {
    page.props.alternates = { ...HOME };
    page.flash = {};
});

const Content = defineComponent({ render: () => h('h1', 'Page content') });

async function mountIn(layout: object, locale: Locale = 'en') {
    await activateLocale(locale);

    return mount(layout, { slots: { default: () => h(Content) }, attachTo: document.body });
}

describe('LocaleSwitcher', () => {
    it('links to this page in every language, each in its own language', async () => {
        const links = (await mountIn(LocaleSwitcher, 'ms')).findAll('a');

        expect(links.map((a) => [a.attributes('href'), a.attributes('lang'), a.attributes('hreflang'), a.text()])).toEqual([
            ['/', 'en', 'en', 'English'],
            ['/ms', 'ms', 'ms', 'Bahasa Melayu'],
            ['/ar', 'ar', 'ar', 'العربية'],
        ]);
    });

    it('marks the current language as the current page', async () => {
        const links = (await mountIn(LocaleSwitcher, 'ar')).findAll('a');

        expect(links.map((a) => a.attributes('aria-current'))).toEqual([undefined, undefined, 'page']);
    });

    it('follows the alternates of each visit, not the first page (M3 review)', async () => {
        const switcher = await mountIn(LocaleSwitcher);

        page.props.alternates = { en: '/products/kopi', ms: '/ms/products/kopi', ar: '/ar/products/kopi' };
        await nextTick();

        expect(switcher.findAll('a').map((a) => a.attributes('href'))).toEqual([
            '/products/kopi',
            '/ms/products/kopi',
            '/ar/products/kopi',
        ]);
    });

    it('is hidden on pages that are not localised', async () => {
        page.props.alternates = [] as never;

        expect((await mountIn(LocaleSwitcher)).find('nav').exists()).toBe(false);
    });

    it('is a labelled navigation landmark with no axe violations', async () => {
        const switcher = await mountIn(LocaleSwitcher, 'ar');

        expect(switcher.get('nav').attributes('aria-label')).toBe('اللغة');
        expect(await axeViolations(switcher.element)).toEqual([]);
    });
});

describe.each([
    ['StorefrontLayout', StorefrontLayout],
    ['CheckoutLayout', CheckoutLayout],
    ['VendorLayout', VendorLayout],
] as const)('%s', (_name, layout) => {
    it('starts with a skip link to the main content', async () => {
        const wrapper = await mountIn(layout);
        const first = wrapper.find('a, button');

        expect(first.attributes('href')).toBe('#main');
        expect(first.text()).toBe('Skip to content');
        expect(wrapper.get('main').attributes('id')).toBe('main');
        expect(wrapper.get('main').attributes('tabindex')).toBe('-1');
    });

    it('renders the page inside <main>, after a banner header', async () => {
        const wrapper = await mountIn(layout);

        expect(wrapper.get('main h1').text()).toBe('Page content');
        expect(wrapper.find('header').exists()).toBe(true);
        expect(wrapper.find('[data-test="toast-region"]').exists()).toBe(true);
    });

    it.each(['en', 'ms', 'ar'] as const)('has no axe violations in %s', async (locale) => {
        expect(await axeViolations((await mountIn(layout, locale)).element)).toEqual([]);
    });
});

describe('StorefrontLayout', () => {
    it.each([
        ['en', '/'],
        ['ms', '/ms'],
        ['ar', '/ar'],
    ] as const)('links the brand to the %s home page', async (locale, href) => {
        expect((await mountIn(StorefrontLayout, locale)).get('header a').attributes('href')).toBe(href);
    });

    it.each([
        ['en', 'Cart, 0 items'],
        ['ms', 'Troli, 0 item'],
        ['ar', 'السلة، لا توجد منتجات'],
    ] as const)('announces the cart count in %s', async (locale, label) => {
        const cart = (await mountIn(StorefrontLayout, locale)).get('[data-test="cart-indicator"]');

        expect(cart.get('.sr-only').text()).toBe(label);
        expect(cart.find('a').exists()).toBe(false);
    });
});

describe('CheckoutLayout', () => {
    it('keeps the header quiet: no cart, just the brand, security note and language', async () => {
        const wrapper = await mountIn(CheckoutLayout, 'ar');

        expect(wrapper.find('[data-test="cart-indicator"]').exists()).toBe(false);
        expect(wrapper.get('header').text()).toContain('دفع آمن');
        expect(wrapper.findAll('header nav a')).toHaveLength(3);
    });
});

describe('VendorLayout', () => {
    it('labels its menu and marks the current section', async () => {
        const wrapper = await mountIn(VendorLayout);
        const menu = wrapper.get('nav[aria-label="Store menu"]');

        expect(menu.get('[aria-current="page"]').text()).toBe('Dashboard');
        expect(menu.findAll('li')).toHaveLength(7);
    });

    it('has no links to pages that do not exist yet', async () => {
        const menu = (await mountIn(VendorLayout)).get('nav[aria-label="Store menu"]');

        expect(menu.findAll('a')).toHaveLength(0);
        expect(menu.text()).toMatch(/Orders\s*· coming soon/);
    });

    it('is translated and right-to-left ready in Arabic', async () => {
        const wrapper = await mountIn(VendorLayout, 'ar');

        expect(wrapper.get('nav[aria-label="قائمة المتجر"] [aria-current="page"]').text()).toBe('لوحة التحكم');
        expect(wrapper.html()).not.toMatch(/\b(ml|mr|pl|pr|left|right)-/);
    });
});
