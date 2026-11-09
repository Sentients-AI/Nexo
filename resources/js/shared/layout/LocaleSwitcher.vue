<script setup lang="ts">
// Links to the current page in each language (F-8). The URLs come from the
// server's `alternates` prop, which Inertia refreshes on every visit, never
// from the DOM, so they are always this page's (M3 review). Hidden on pages
// that are not localised (no alternates).
//
// Plain links rather than a <select>: they work without JavaScript, are
// crawlable, and each carries its own lang and hreflang.
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { LOCALE_NAMES, LOCALES, useI18n } from '@/i18n';

const page = usePage();
const { t, locale } = useI18n();

const links = computed(() =>
    LOCALES.flatMap((code) => {
        const href = page.props.alternates?.[code];

        return href ? [{ code, href, name: LOCALE_NAMES[code], current: code === locale.value }] : [];
    }),
);
</script>

<template>
    <nav v-if="links.length > 1" :aria-label="t('ui.layout.language')">
        <ul class="flex flex-wrap items-center gap-1 text-sm">
            <li v-for="link in links" :key="link.code">
                <Link
                    :href="link.href"
                    :lang="link.code"
                    :hreflang="link.code"
                    :aria-current="link.current ? 'page' : undefined"
                    class="inline-flex h-9 items-center rounded-md px-2.5 text-fg-muted transition-colors hover:bg-muted hover:text-fg aria-[current]:font-medium aria-[current]:text-fg"
                >
                    {{ link.name }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
