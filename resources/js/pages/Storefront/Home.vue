<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('storefront.home.title')" />

    <main class="mx-auto flex min-h-screen max-w-content flex-col justify-center px-gutter py-section">
        <p class="text-xs font-medium tracking-wide text-fg-muted uppercase">{{ t('storefront.home.eyebrow') }}</p>
        <h1 class="mt-4 text-5xl font-semibold tracking-tight">Nexo</h1>
        <p class="mt-4 max-w-xl text-lg text-fg-muted">{{ t('storefront.home.tagline') }}</p>
    </main>
</template>
