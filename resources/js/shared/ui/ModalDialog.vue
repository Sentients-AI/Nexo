<script setup lang="ts">
// Modal dialog on the native <dialog> element: showModal() makes the page
// behind it inert, traps focus, and Escape fires `cancel`. Controlled with
// v-model:open. Focus returns to whatever was focused before it opened.
import { onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from '@/i18n';

const props = withDefaults(defineProps<{ title: string; description?: string; dismissible?: boolean }>(), {
    dismissible: true,
});

const open = defineModel<boolean>('open', { default: false });
const dialog = ref<HTMLDialogElement | null>(null);
const id = useId();
const { t } = useI18n();
let returnFocusTo: HTMLElement | null = null;

function show(): void {
    const element = dialog.value;

    if (!element || element.open) return;

    returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    element.showModal();
    document.documentElement.style.overflow = 'hidden';
}

function hide(): void {
    const element = dialog.value;

    if (element?.open) element.close();

    document.documentElement.style.overflow = '';
    returnFocusTo?.focus();
    returnFocusTo = null;
}

watch(open, (value) => (value ? show() : hide()), { flush: 'post' });
watch(dialog, (element) => element && open.value && show(), { flush: 'post' });

function onCancel(event: Event): void {
    // Escape: close through the model so the parent stays in control.
    event.preventDefault();

    if (props.dismissible) open.value = false;
}

function onBackdropClick(event: MouseEvent): void {
    if (props.dismissible && event.target === dialog.value) open.value = false;
}

onBeforeUnmount(() => {
    if (open.value) hide();
});
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="`${id}-title`"
        :aria-describedby="description ? `${id}-description` : undefined"
        class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-lg bg-surface p-0 text-fg shadow-lg backdrop:bg-fg/40"
        @cancel="onCancel"
        @click="onBackdropClick"
    >
        <div class="p-6">
            <div class="flex items-start justify-between gap-4">
                <h2 :id="`${id}-title`" class="text-lg font-semibold tracking-tight">{{ title }}</h2>
                <button
                    v-if="dismissible"
                    type="button"
                    class="-me-2 -mt-1 inline-flex size-9 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-muted hover:text-fg"
                    :aria-label="t('ui.dialog.close')"
                    data-test="dialog-close"
                    @click="open = false"
                >
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
                        />
                    </svg>
                </button>
            </div>
            <p v-if="description" :id="`${id}-description`" class="mt-2 text-fg-muted">{{ description }}</p>
            <div class="mt-4"><slot /></div>
            <div v-if="$slots.actions" class="mt-6 flex flex-wrap justify-end gap-3"><slot name="actions" /></div>
        </div>
    </dialog>
</template>
