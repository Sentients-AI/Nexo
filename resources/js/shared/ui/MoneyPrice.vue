<script setup lang="ts">
// Displays money from the server (ADR-0018 shape) without arithmetic (F3).
// The amount is LTR-isolated so "RM 2,418.50" reads the same inside Arabic.
// With `compareAt`, the original price is struck through and both prices
// are labelled for screen readers.
import { useI18n } from '@/i18n';
import type { Money } from '@/types/money';

withDefaults(defineProps<{ amount: Money; compareAt?: Money | null; size?: 'sm' | 'md' | 'lg' }>(), {
    compareAt: null,
    size: 'md',
});

const { t, formatMoney } = useI18n();
</script>

<template>
    <span class="inline-flex flex-wrap items-baseline gap-x-2">
        <span v-if="compareAt" class="sr-only">{{ t('ui.price.now') }}</span>
        <bdi
            dir="ltr"
            class="font-semibold text-fg tabular-nums"
            :class="{ 'text-sm': size === 'sm', 'text-base': size === 'md', 'text-xl': size === 'lg' }"
            >{{ formatMoney(amount) }}</bdi
        >
        <template v-if="compareAt">
            <span class="sr-only">{{ t('ui.price.was') }}</span>
            <s class="text-sm text-fg-muted"
                ><bdi dir="ltr" class="tabular-nums">{{ formatMoney(compareAt) }}</bdi></s
            >
        </template>
    </span>
</template>
