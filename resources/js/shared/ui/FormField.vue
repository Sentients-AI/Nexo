<script setup lang="ts">
import { computed, provide, useId } from 'vue';
import { useI18n } from '@/i18n';
import { FIELD_CONTEXT } from './field-context';

const props = withDefaults(
    defineProps<{
        label: string;
        hint?: string;
        /** Validation message; when set the control is marked invalid. */
        error?: string;
        required?: boolean;
    }>(),
    { required: false },
);

const { t } = useI18n();
const id = useId();
const controlId = `${id}-control`;
const hintId = `${id}-hint`;
const errorId = `${id}-error`;

// Error first, so screen readers announce it before the hint.
const describedBy = computed(
    () => [props.error ? errorId : null, props.hint ? hintId : null].filter(Boolean).join(' ') || undefined,
);

provide(FIELD_CONTEXT, {
    controlId,
    describedBy,
    invalid: computed(() => Boolean(props.error)),
    required: computed(() => props.required),
});
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label :for="controlId" class="text-sm font-medium text-fg">
            {{ label }}
            <span v-if="required" class="text-fg-muted" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">({{ t('ui.field.required') }})</span>
        </label>
        <slot />
        <p v-if="error" :id="errorId" class="flex items-start gap-1.5 text-sm text-danger">
            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                    clip-rule="evenodd"
                />
            </svg>
            <span>{{ error }}</span>
        </p>
        <p v-if="hint" :id="hintId" class="text-sm text-fg-muted">{{ hint }}</p>
    </div>
</template>
