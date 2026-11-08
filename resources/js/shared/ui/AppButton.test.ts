import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axeViolations } from '@/test/axe';
import AppButton from './AppButton.vue';

describe('AppButton', () => {
    it('is a native button of type "button" by default, so it never submits by accident', () => {
        const button = mount(AppButton, { slots: { default: 'Save' } }).get('button');

        expect(button.attributes('type')).toBe('button');
        expect(button.text()).toBe('Save');
    });

    it('emits clicks, and is reachable by keyboard as a native button', async () => {
        const wrapper = mount(AppButton, { slots: { default: 'Go' }, attachTo: document.body });
        const button = wrapper.get('button');

        (button.element as HTMLButtonElement).focus();
        expect(document.activeElement).toBe(button.element);

        await button.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('while loading: disabled, aria-busy, spinner hidden from AT, and announces "Loading"', () => {
        const wrapper = mount(AppButton, { props: { loading: true }, slots: { default: 'Pay' } });
        const button = wrapper.get('button');

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('aria-busy')).toBe('true');
        expect(wrapper.get('[data-test="spinner"]').attributes('aria-hidden')).toBe('true');
        expect(wrapper.get('.sr-only').text()).toBe('Loading');
    });

    it.each(['primary', 'secondary', 'ghost', 'danger'] as const)('%s variant has no axe violations', async (variant) => {
        const wrapper = mount(AppButton, { props: { variant }, slots: { default: 'Action' }, attachTo: document.body });

        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});
