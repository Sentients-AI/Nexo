<script setup lang="ts">
// Header cart count. A placeholder until the cart exists (FR-CART-01): it
// shows the count but is not a link yet, so it never points to a page that
// 404s. The spoken label carries the count and its plural.
import { useI18n } from '@/i18n';

withDefaults(defineProps<{ count?: number }>(), { count: 0 });

const { t, formatNumber } = useI18n();
</script>

<template>
    <p class="inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 text-sm text-fg" data-test="cart-indicator">
        <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path d="M3.5 6.5h13l-1 9.25a1.5 1.5 0 0 1-1.5 1.25h-8a1.5 1.5 0 0 1-1.5-1.25l-1-9.25Z" stroke-linejoin="round" />
            <path d="M7 8V5.5a3 3 0 0 1 6 0V8" stroke-linecap="round" />
        </svg>
        <span aria-hidden="true">{{ formatNumber(count) }}</span>
        <span class="sr-only">{{ t('storefront.header.cart', { count }) }}</span>
    </p>
</template>
