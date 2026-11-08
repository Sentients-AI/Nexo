<script setup lang="ts">
// Renders the toast queue in a live region. Mount once per layout.
// Flash data from the server ({ toast: { tone, key, params } }) is
// translated with t() and queued on every visit that carries it.
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from '@/i18n';
import { dismiss, pause, push, resume, useToasts } from './toasts';

const { toasts } = useToasts();
const { t } = useI18n();
const page = usePage();

watch(
    () => page.flash?.toast,
    (toast) => {
        if (toast) push(toast.tone, t(toast.key, toast.params));
    },
    { immediate: true },
);

const DOT = { success: 'bg-success', info: 'bg-info', error: 'bg-danger' } as const;
</script>

<template>
    <div
        aria-live="polite"
        class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-3 p-4 sm:items-end"
        data-test="toast-region"
        @mouseenter="pause"
        @mouseleave="resume"
        @focusin="pause"
        @focusout="resume"
    >
        <div
            v-for="toast in toasts"
            :key="toast.id"
            :role="toast.tone === 'error' ? 'alert' : 'status'"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-border bg-surface p-4 text-sm text-fg shadow-md"
            data-test="toast"
        >
            <span :class="DOT[toast.tone]" class="mt-1.5 size-2 shrink-0 rounded-full" aria-hidden="true" />
            <p class="min-w-0 flex-1">{{ toast.message }}</p>
            <button
                type="button"
                class="-m-1 inline-flex size-7 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-muted hover:text-fg"
                :aria-label="t('ui.toast.dismiss')"
                @click="dismiss(toast.id)"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
                    />
                </svg>
            </button>
        </div>
    </div>
</template>
