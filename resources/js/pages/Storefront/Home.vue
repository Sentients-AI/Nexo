<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import StorefrontLayout from '@/storefront/layouts/StorefrontLayout.vue';

defineOptions({ layout: StorefrontLayout });

const { t } = useI18n();
</script>

<template>
    <Head :title="t('storefront.home.title')" />

    <section class="mx-auto flex w-full max-w-content flex-1 flex-col justify-center px-gutter py-section">
        <p class="text-xs font-medium tracking-wide text-fg-muted uppercase">{{ t('storefront.home.eyebrow') }}</p>
        <h1 class="mt-4 text-5xl font-semibold tracking-tight">Nexo</h1>
        <p class="mt-4 max-w-xl text-lg text-fg-muted">{{ t('storefront.home.tagline') }}</p>
    </section>
</template>
