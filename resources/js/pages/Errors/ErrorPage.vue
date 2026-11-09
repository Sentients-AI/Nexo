<script setup lang="ts">
// Rendered by App\Http\Errors\ErrorPages for 403, 404, 419, 500 and 503.
// Shared by the storefront and the vendor dashboard (F1), so it has its own
// minimal frame instead of either surface's layout. It only knows the
// status code; the server never sends exception details (NFR-SEC-07).
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { homePath, useI18n } from '@/i18n';
import TextLink from '@/shared/ui/TextLink.vue';

const props = defineProps<{ status: 403 | 404 | 419 | 500 | 503 }>();

const { t, locale } = useI18n();

const KEYS = {
    403: 'forbidden',
    404: 'not_found',
    419: 'page_expired',
    500: 'server_error',
    503: 'unavailable',
} as const;

const key = computed(() => KEYS[props.status] ?? 'server_error');
const title = computed(() => t(`ui.errors.${key.value}.title`));
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-screen flex-col">
        <header class="border-b border-border-subtle">
            <div class="mx-auto flex h-16 max-w-content items-center px-gutter">
                <span class="text-lg font-semibold tracking-tight" lang="en" dir="ltr">Nexo</span>
            </div>
        </header>

        <main class="mx-auto flex w-full max-w-content flex-1 flex-col justify-center px-gutter py-section">
            <p class="text-sm font-medium text-fg-muted">{{ t('ui.errors.code', { status }) }}</p>
            <h1 class="mt-3 text-4xl font-semibold tracking-tight">{{ title }}</h1>
            <p class="mt-4 max-w-xl text-lg text-fg-muted">{{ t(`ui.errors.${key}.description`) }}</p>
            <div v-if="status !== 503" class="mt-8">
                <TextLink :href="homePath(locale)" appearance="primary">{{ t('ui.errors.home') }}</TextLink>
            </div>
        </main>
    </div>
</template>
