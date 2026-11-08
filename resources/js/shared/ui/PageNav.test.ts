import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { activateLocale } from '@/i18n';
import { axeViolations } from '@/test/axe';
import { pageWindow } from './page-window';
import PageNav from './PageNav.vue';

const href = (page: number) => `/products?page=${page}`;

describe('pageWindow', () => {
    it.each([
        [1, 1, [1]],
        [1, 5, [1, 2, 3, 4, 5]],
        [4, 7, [1, 2, 3, 4, 5, 6, 7]],
        [1, 10, [1, 2, null, 10]],
        [5, 10, [1, null, 4, 5, 6, null, 10]],
        [10, 10, [1, null, 9, 10]],
        [3, 10, [1, 2, 3, 4, null, 10]],
        [4, 10, [1, 2, 3, 4, 5, null, 10]],
        [99, 10, [1, null, 9, 10]],
    ])('page %i of %i → %j', (page, last, expected) => {
        expect(pageWindow(page, last)).toEqual(expected);
    });
});

describe('PageNav', () => {
    it('renders nothing for a single page', () => {
        expect(mount(PageNav, { props: { page: 1, lastPage: 1, href } }).find('nav').exists()).toBe(false);
    });

    it('is a labelled nav; the current page is marked and not a link', () => {
        const wrapper = mount(PageNav, { props: { page: 5, lastPage: 10, href } });
        const current = wrapper.get('[aria-current="page"]');

        expect(wrapper.get('nav').attributes('aria-label')).toBe('Pagination');
        expect(current.element.tagName).toBe('SPAN');
        expect(current.attributes('aria-label')).toBe('Page 5');
        expect(wrapper.get('[data-test="previous"]').attributes('href')).toBe('/products?page=4');
        expect(wrapper.get('[data-test="next"]').attributes('href')).toBe('/products?page=6');
    });

    it('accepts a custom landmark label for pages with more than one', () => {
        const wrapper = mount(PageNav, { props: { page: 1, lastPage: 3, href, label: 'Reviews pages' } });

        expect(wrapper.get('nav').attributes('aria-label')).toBe('Reviews pages');
    });

    it('omits previous on the first page and next on the last', () => {
        expect(mount(PageNav, { props: { page: 1, lastPage: 3, href } }).find('[data-test="previous"]').exists()).toBe(false);
        expect(mount(PageNav, { props: { page: 3, lastPage: 3, href } }).find('[data-test="next"]').exists()).toBe(false);
    });

    it('flips the arrows in RTL and translates its labels', async () => {
        await activateLocale('ar');

        const wrapper = mount(PageNav, { props: { page: 2, lastPage: 3, href } });

        expect(wrapper.get('nav').attributes('aria-label')).toBe('ترقيم الصفحات');
        expect(wrapper.get('[data-test="next"]').text()).toBe('التالي');
        expect(wrapper.get('[data-test="next"] svg').classes()).toContain('rtl:-scale-x-100');
    });

    it('has no axe violations', async () => {
        const wrapper = mount(PageNav, { props: { page: 5, lastPage: 10, href }, attachTo: document.body });

        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});
