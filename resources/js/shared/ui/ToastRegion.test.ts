import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { axeViolations } from '@/test/axe';
import type { Flash } from '@/types/shared';
import ToastRegion from './ToastRegion.vue';
import { DURATION_MS, push } from './toasts';

const page = reactive<{ flash: Flash }>({ flash: {} });

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => page,
}));

beforeEach(() => {
    vi.useFakeTimers();
    page.flash = {};
});

afterEach(() => {
    vi.useRealTimers();
});

describe('ToastRegion', () => {
    it('is a polite live region; success is a status, errors are alerts', async () => {
        const wrapper = mount(ToastRegion, { attachTo: document.body });

        push('success', 'Added to cart');
        push('error', 'Payment failed');
        await nextTick();

        expect(wrapper.get('[data-test="toast-region"]').attributes('aria-live')).toBe('polite');
        expect(wrapper.findAll('[data-test="toast"]').map((t) => t.attributes('role'))).toEqual(['status', 'alert']);
    });

    it('dismisses success toasts after the duration but keeps errors until dismissed (WCAG 2.2.1)', async () => {
        const wrapper = mount(ToastRegion, { attachTo: document.body });

        push('success', 'Saved');
        push('error', 'Could not save');
        await nextTick();
        vi.advanceTimersByTime(DURATION_MS + 1);
        await nextTick();

        expect(wrapper.findAll('[data-test="toast"]').map((t) => t.text())).toEqual(['Could not save']);

        await wrapper.get('[data-test="toast"] button').trigger('click');
        expect(wrapper.findAll('[data-test="toast"]')).toHaveLength(0);
    });

    it('pauses auto-dismiss while the pointer or keyboard focus is on the region', async () => {
        const wrapper = mount(ToastRegion, { attachTo: document.body });
        const region = wrapper.get('[data-test="toast-region"]');

        push('info', 'Shipping updated');
        await nextTick();
        vi.advanceTimersByTime(DURATION_MS - 1000);

        await region.trigger('focusin');
        vi.advanceTimersByTime(DURATION_MS * 2);
        await nextTick();
        expect(wrapper.findAll('[data-test="toast"]')).toHaveLength(1);

        await region.trigger('focusout');
        vi.advanceTimersByTime(1001);
        await nextTick();
        expect(wrapper.findAll('[data-test="toast"]')).toHaveLength(0);
    });

    it('translates server flash data into a toast', async () => {
        const wrapper = mount(ToastRegion, { attachTo: document.body });

        page.flash = { toast: { tone: 'success', key: 'ui.pagination.page', params: { page: 2 } } };
        await nextTick();

        expect(wrapper.get('[data-test="toast"]').text()).toContain('Page 2');
    });

    it('labels the dismiss button and has no axe violations', async () => {
        vi.useRealTimers();
        const wrapper = mount(ToastRegion, { attachTo: document.body });

        push('success', 'Saved');
        await nextTick();

        expect(wrapper.get('[data-test="toast"] button').attributes('aria-label')).toBe('Dismiss notification');
        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});
