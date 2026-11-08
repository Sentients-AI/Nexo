<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/i18n';
import { buttonClasses, type ButtonSize, type ButtonVariant } from './button-classes';

const props = withDefaults(
    defineProps<{
        variant?: ButtonVariant;
        size?: ButtonSize;
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        /** Shows a spinner, sets aria-busy and blocks further clicks. */
        loading?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', size: 'md', type: 'button', disabled: false, loading: false, block: false },
);

const { t } = useI18n();
const classes = computed(() => buttonClasses(props.variant, props.size, props.block));
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading || undefined">
        <svg
            v-if="loading"
            class="size-4 motion-safe:animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
            data-test="spinner"
        >
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <slot />
        <span v-if="loading" class="sr-only">{{ t('ui.common.loading') }}</span>
    </button>
</template>
