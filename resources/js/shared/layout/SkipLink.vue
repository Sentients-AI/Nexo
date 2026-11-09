<script setup lang="ts">
// First focusable element of every layout: lets keyboard and screen-reader
// users jump past the header to <main id="main"> (WCAG 2.4.1, NFR-A11Y-02).
import { useI18n } from '@/i18n';

const { t } = useI18n();
</script>

<template>
    <a
        href="#main"
        class="sr-only z-50 rounded-md bg-surface px-4 py-2 text-sm font-medium text-fg shadow-md focus:not-sr-only focus:fixed focus:start-4 focus:top-4"
    >
        {{ t('ui.layout.skip_to_content') }}
    </a>
</template>
