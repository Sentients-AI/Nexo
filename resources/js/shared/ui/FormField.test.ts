import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, ref } from 'vue';
import { axeViolations } from '@/test/axe';
import CheckboxInput from './CheckboxInput.vue';
import FormField from './FormField.vue';
import SelectInput from './SelectInput.vue';
import TextInput from './TextInput.vue';

function fieldWith(control: 'text' | 'select', props: Record<string, unknown> = {}) {
    return defineComponent({
        components: { FormField, TextInput, SelectInput },
        setup: () => ({ value: ref(''), props }),
        template:
            control === 'text'
                ? `<FormField v-bind="props"><TextInput v-model="value" /></FormField>`
                : `<FormField v-bind="props"><SelectInput v-model="value" placeholder="Choose" :options="[{ value: 'my', label: 'Malaysia' }]" /></FormField>`,
    });
}

describe('FormField with TextInput', () => {
    it('labels the control', () => {
        const wrapper = mount(fieldWith('text', { label: 'Email' }));

        expect(wrapper.get('label').attributes('for')).toBe(wrapper.get('input').attributes('id'));
    });

    it('links the error and hint with aria-describedby, error first, and marks the control invalid (NFR-A11Y-02)', () => {
        const wrapper = mount(fieldWith('text', { label: 'Email', hint: 'We never share it.', error: 'Enter a valid email.' }));
        const input = wrapper.get('input');
        const [first, second] = (input.attributes('aria-describedby') ?? '').split(' ');

        expect(input.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get(`#${first}`).text()).toBe('Enter a valid email.');
        expect(wrapper.get(`#${second}`).text()).toBe('We never share it.');
    });

    it('has no aria-describedby or aria-invalid when there is nothing to describe', () => {
        const input = mount(fieldWith('text', { label: 'Name' })).get('input');

        expect(input.attributes('aria-describedby')).toBeUndefined();
        expect(input.attributes('aria-invalid')).toBeUndefined();
    });

    it('marks required fields natively and announces it', () => {
        const wrapper = mount(fieldWith('text', { label: 'Name', required: true }));

        expect(wrapper.get('input').attributes('required')).toBeDefined();
        expect(wrapper.get('label .sr-only').text()).toBe('(required)');
    });

    it('updates v-model as the user types', async () => {
        const wrapper = mount(fieldWith('text', { label: 'Name' }));

        await wrapper.get('input').setValue('Aisyah');
        expect((wrapper.vm as unknown as { value: string }).value).toBe('Aisyah');
    });

    it.each(['email', 'url', 'tel'] as const)('keeps %s values left to right, even on RTL pages', (type) => {
        const input = mount(TextInput, { props: { type } }).get('input');

        expect(input.attributes('dir')).toBe('ltr');
    });

    it('lets ordinary text follow the page direction', () => {
        expect(mount(TextInput).get('input').attributes('dir')).toBeUndefined();
    });

    it('has no axe violations, valid or invalid', async () => {
        const valid = mount(fieldWith('text', { label: 'Name', hint: 'As on your IC.' }), { attachTo: document.body });
        const invalid = mount(fieldWith('text', { label: 'Email', error: 'Required.' }), { attachTo: document.body });

        expect(await axeViolations(valid.element)).toEqual([]);
        expect(await axeViolations(invalid.element)).toEqual([]);
    });
});

describe('FormField with SelectInput', () => {
    it('is a labelled native select with a disabled placeholder', () => {
        const wrapper = mount(fieldWith('select', { label: 'Country', error: 'Pick one.' }));
        const select = wrapper.get('select');

        expect(wrapper.get('label').attributes('for')).toBe(select.attributes('id'));
        expect(select.attributes('aria-invalid')).toBe('true');
        expect(select.get('option[value=""]').attributes('disabled')).toBeDefined();
    });

    it('has no axe violations', async () => {
        const wrapper = mount(fieldWith('select', { label: 'Country' }), { attachTo: document.body });

        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});

describe('CheckboxInput', () => {
    it('is a native checkbox toggled by its label', async () => {
        const wrapper = mount(CheckboxInput, { props: { label: 'Gift wrap', modelValue: false } });
        const input = wrapper.get('input');

        expect(input.attributes('type')).toBe('checkbox');
        expect(wrapper.get('label').attributes('for')).toBe(input.attributes('id'));

        await input.setValue(true);
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true]);
    });

    it('links its error and hint', () => {
        const wrapper = mount(CheckboxInput, { props: { label: 'Accept terms', error: 'Required.', hint: 'Read them first.' } });
        const ids = (wrapper.get('input').attributes('aria-describedby') ?? '').split(' ');

        expect(ids.map((id) => wrapper.get(`#${id}`).text())).toEqual(['Required.', 'Read them first.']);
    });

    it('has no axe violations', async () => {
        const wrapper = mount(CheckboxInput, { props: { label: 'Gift wrap', hint: 'RM 5' }, attachTo: document.body });

        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});
