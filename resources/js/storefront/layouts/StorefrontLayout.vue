<script setup lang="ts">
// Frame for storefront pages: skip link, header (brand, language switcher,
// cart count) and the toast region. Pages opt in with
// `defineOptions({ layout: StorefrontLayout })`; Inertia keeps the layout
// mounted across visits, so the header and pending toasts survive.
import { Link } from '@inertiajs/vue3';
import { homePath, useI18n } from '@/i18n';
import LocaleSwitcher from '@/shared/layout/LocaleSwitcher.vue';
import SkipLink from '@/shared/layout/SkipLink.vue';
import ToastRegion from '@/shared/ui/ToastRegion.vue';
import CartIndicator from '../components/CartIndicator.vue';

const { locale } = useI18n();
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <SkipLink />

        <header class="border-b border-border-subtle bg-surface">
            <div class="mx-auto flex min-h-16 max-w-content flex-wrap items-center gap-x-6 gap-y-2 px-gutter py-3">
                <Link :href="homePath(locale)" class="text-lg font-semibold tracking-tight text-fg">Nexo</Link>

                <div class="ms-auto flex flex-wrap items-center gap-2">
                    <LocaleSwitcher />
                    <CartIndicator />
                </div>
            </div>
        </header>

        <main id="main" tabindex="-1" class="flex flex-1 flex-col focus:outline-none">
            <slot />
        </main>

        <ToastRegion />
    </div>
</template>
