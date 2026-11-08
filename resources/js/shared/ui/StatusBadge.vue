<script setup lang="ts">
// A short status label. `tone` is generic: callers map their own domain
// states (order, payout, stock) to a tone, so the badge knows no vocabulary.
export type BadgeTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

withDefaults(defineProps<{ tone?: BadgeTone }>(), { tone: 'neutral' });

const TONES: Record<BadgeTone, string> = {
    neutral: 'bg-muted text-fg',
    success: 'bg-success-bg text-success',
    warning: 'bg-warning-bg text-warning',
    danger: 'bg-danger-bg text-danger',
    info: 'bg-info-bg text-info',
};
</script>

<template>
    <span
        :class="TONES[tone]"
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap"
    >
        <slot />
    </span>
</template>
