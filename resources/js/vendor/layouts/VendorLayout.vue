<script setup lang="ts">
// Vendor dashboard frame, static for now: no auth, no vendor context and no
// routes behind the menu yet (those wait on ADR-0011, M6). Menu items with
// no page are shown as "coming soon" text rather than links that 404.
//
// Sidebar on wide screens, a scrolling row under the header on narrow ones.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/i18n';
import LocaleSwitcher from '@/shared/layout/LocaleSwitcher.vue';
import SkipLink from '@/shared/layout/SkipLink.vue';
import ToastRegion from '@/shared/ui/ToastRegion.vue';

interface MenuItem {
    key: 'dashboard' | 'orders' | 'products' | 'stock' | 'earnings' | 'team' | 'settings';
    href?: string;
}

const props = withDefaults(defineProps<{ current?: MenuItem['key'] }>(), { current: 'dashboard' });

const { t } = useI18n();

// VJ-1…VJ-7. An item gets an href when its page exists.
const MENU: readonly MenuItem[] = [
    { key: 'dashboard' },
    { key: 'orders' },
    { key: 'products' },
    { key: 'stock' },
    { key: 'earnings' },
    { key: 'team' },
    { key: 'settings' },
];

const items = computed(() =>
    MENU.map((item) => ({ ...item, label: t(`vendor.nav.${item.key}`), isCurrent: item.key === props.current })),
);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-subtle">
        <SkipLink />

        <header class="border-b border-border-subtle bg-surface">
            <div class="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-2 px-gutter py-2">
                <span class="text-base font-semibold tracking-tight text-fg">Nexo</span>
                <span class="text-sm text-fg-muted">{{ t('vendor.layout.title') }}</span>

                <div class="ms-auto">
                    <LocaleSwitcher />
                </div>
            </div>
        </header>

        <div class="flex min-w-0 flex-1 flex-col md:flex-row">
            <nav
                :aria-label="t('vendor.layout.menu')"
                class="min-w-0 border-b border-border-subtle bg-surface md:w-60 md:shrink-0 md:border-e md:border-b-0"
            >
                <p class="hidden px-gutter pt-5 pb-2 text-sm font-medium text-fg md:block">{{ t('vendor.layout.store') }}</p>
                <ul class="flex gap-1 overflow-x-auto px-3 py-2 md:flex-col md:overflow-visible">
                    <li v-for="item in items" :key="item.key" class="shrink-0">
                        <Link
                            v-if="item.href"
                            :href="item.href"
                            :aria-current="item.isCurrent ? 'page' : undefined"
                            class="flex h-9 items-center rounded-md px-3 text-sm text-fg hover:bg-muted aria-[current]:bg-muted aria-[current]:font-medium"
                        >
                            {{ item.label }}
                        </Link>
                        <span
                            v-else
                            :aria-current="item.isCurrent ? 'page' : undefined"
                            class="flex h-9 items-center gap-2 rounded-md px-3 text-sm whitespace-nowrap aria-[current]:bg-muted aria-[current]:font-medium aria-[current]:text-fg"
                            :class="item.isCurrent ? '' : 'text-fg-muted'"
                        >
                            {{ item.label }}
                            <span v-if="!item.isCurrent" class="text-xs">· {{ t('vendor.layout.soon') }}</span>
                        </span>
                    </li>
                </ul>
            </nav>

            <main id="main" tabindex="-1" class="min-w-0 flex-1 px-gutter py-8 focus:outline-none">
                <slot />
            </main>
        </div>

        <ToastRegion />
    </div>
</template>
