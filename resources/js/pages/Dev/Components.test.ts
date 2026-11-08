import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';
import { activateLocale } from '@/i18n';
import { axeViolations } from '@/test/axe';
import Components from './Components.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: defineComponent({ name: 'Head', render: () => null }),
    usePage: () => ({ flash: {} }),
}));

const SECTIONS = ['button', 'form', 'badge', 'card', 'overlay', 'pagination'];

describe('Dev/Components gallery', () => {
    it.each([
        ['en', 'ltr', 'Save changes', 'Pagination'],
        ['ms', 'ltr', 'Simpan perubahan', 'Penomboran halaman'],
        ['ar', 'rtl', 'حفظ التغييرات', 'ترقيم الصفحات'],
    ] as const)('renders every component in %s', async (locale, dir, sample, paginationLabel) => {
        await activateLocale(locale);

        const wrapper = mount(Components, { attachTo: document.body });

        for (const section of SECTIONS) expect(wrapper.find(`[data-test="section-${section}"]`).exists()).toBe(true);

        expect(document.documentElement.dir).toBe(dir);
        expect(wrapper.text()).toContain(sample);
        expect(wrapper.get('nav').attributes('aria-label')).toBe(paginationLabel);
        expect(wrapper.text()).toContain('RM 2,418.50');
    });

    it('switches locale from its own controls', async () => {
        const wrapper = mount(Components, { attachTo: document.body });

        await wrapper.get('[data-test="locale-ar"]').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
        await nextTick();

        expect(document.documentElement.lang).toBe('ar');
        expect(wrapper.get('[data-test="locale-ar"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.text()).toContain('حفظ التغييرات');
    });

    it('has no axe violations', async () => {
        const wrapper = mount(Components, { attachTo: document.body });

        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});
