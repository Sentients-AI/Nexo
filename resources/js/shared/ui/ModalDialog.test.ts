import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, nextTick, ref } from 'vue';
import { axeViolations } from '@/test/axe';
import ModalDialog from './ModalDialog.vue';

const Harness = defineComponent({
    components: { ModalDialog },
    props: { dismissible: { type: Boolean, default: true } },
    setup: () => ({ open: ref(false) }),
    template: `
        <button data-test="trigger" @click="open = true">Remove item</button>
        <ModalDialog v-model:open="open" title="Remove item?" description="It leaves your cart." :dismissible="dismissible">
            <p>Body</p>
            <template #actions><button data-test="confirm" @click="open = false">Remove</button></template>
        </ModalDialog>`,
});

async function openDialog(dismissible = true) {
    const wrapper = mount(Harness, { props: { dismissible }, attachTo: document.body });
    const trigger = wrapper.get('[data-test="trigger"]');

    (trigger.element as HTMLElement).focus();
    await trigger.trigger('click');
    await nextTick();

    return { wrapper, trigger, dialog: wrapper.get('dialog').element as HTMLDialogElement };
}

describe('ModalDialog', () => {
    it('opens as a modal labelled by its title and described by its description', async () => {
        const { wrapper, dialog } = await openDialog();

        expect(dialog.open).toBe(true);
        expect(wrapper.get(`#${dialog.getAttribute('aria-labelledby')}`).text()).toBe('Remove item?');
        expect(wrapper.get(`#${dialog.getAttribute('aria-describedby')}`).text()).toBe('It leaves your cart.');
    });

    it('moves focus into the dialog and locks page scroll while open', async () => {
        const { dialog } = await openDialog();

        expect(dialog.contains(document.activeElement)).toBe(true);
        expect(document.documentElement.style.overflow).toBe('hidden');
    });

    it('closes on Escape and returns focus to the trigger', async () => {
        const { trigger, dialog } = await openDialog();

        dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await nextTick();
        await nextTick();

        expect(dialog.open).toBe(false);
        expect(document.activeElement).toBe(trigger.element);
        expect(document.documentElement.style.overflow).toBe('');
    });

    it('closes from the labelled close button and from an action', async () => {
        const { wrapper, dialog } = await openDialog();
        const close = wrapper.get('[data-test="dialog-close"]');

        expect(close.attributes('aria-label')).toBe('Close');
        await close.trigger('click');
        await nextTick();
        expect(dialog.open).toBe(false);

        await wrapper.get('[data-test="trigger"]').trigger('click');
        await nextTick();
        await wrapper.get('[data-test="confirm"]').trigger('click');
        await nextTick();
        expect(dialog.open).toBe(false);
    });

    it('ignores Escape and hides the close button when not dismissible', async () => {
        const { wrapper, dialog } = await openDialog(false);

        dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await nextTick();

        expect(dialog.open).toBe(true);
        expect(wrapper.find('[data-test="dialog-close"]').exists()).toBe(false);
    });

    it('has no axe violations while open', async () => {
        const { dialog } = await openDialog();

        expect(await axeViolations(dialog)).toEqual([]);
    });
});
