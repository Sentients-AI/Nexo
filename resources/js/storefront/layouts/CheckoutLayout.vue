<script setup lang="ts">
// Checkout frame: a deliberately quiet header (brand, "Secure checkout",
// language) with no cart or browsing links, so nothing pulls the customer
// out mid-payment. Used by the checkout pages once they exist (CJ-1).
import { Link } from '@inertiajs/vue3';
import { homePath, useI18n } from '@/i18n';
import LocaleSwitcher from '@/shared/layout/LocaleSwitcher.vue';
import SkipLink from '@/shared/layout/SkipLink.vue';
import ToastRegion from '@/shared/ui/ToastRegion.vue';

const { t, locale } = useI18n();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-subtle">
        <SkipLink />

        <header class="border-b border-border-subtle bg-surface">
            <div class="mx-auto flex min-h-16 max-w-content flex-wrap items-center gap-x-6 gap-y-2 px-gutter py-3">
                <Link :href="homePath(locale)" class="text-lg font-semibold tracking-tight text-fg">Nexo</Link>

                <p class="inline-flex items-center gap-1.5 text-sm text-fg-muted">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <rect x="4" y="9" width="12" height="8" rx="1.5" />
                        <path d="M7 9V6.5a3 3 0 0 1 6 0V9" stroke-linecap="round" />
                    </svg>
                    {{ t('storefront.checkout.secure') }}
                </p>

                <div class="ms-auto">
                    <LocaleSwitcher />
                </div>
            </div>
        </header>

        <main id="main" tabindex="-1" class="mx-auto flex w-full max-w-content flex-1 flex-col px-gutter py-8 focus:outline-none">
            <slot />
        </main>

        <ToastRegion />
    </div>
</template>
