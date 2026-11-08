<script setup lang="ts">
// Pagination as a labelled <nav> of links. The server owns paging; `href`
// builds each page's URL. Previous/next arrows flip in RTL. Give `label`
// when a page has more than one (landmark names must be unique).
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/i18n';
import { pageWindow } from './page-window';

const props = withDefaults(
    defineProps<{
        page: number;
        lastPage: number;
        href: (page: number) => string;
        siblings?: number;
        label?: string;
    }>(),
    { siblings: 1 },
);

const { t, formatNumber } = useI18n();
const pages = computed(() => pageWindow(props.page, props.lastPage, props.siblings));

const ITEM =
    'inline-flex h-10 min-w-10 items-center justify-center gap-1 rounded-md px-3 text-sm font-medium tabular-nums transition-colors';
</script>

<template>
    <nav v-if="lastPage > 1" :aria-label="label ?? t('ui.pagination.label')">
        <ul class="flex flex-wrap items-center gap-1">
            <li v-if="page > 1">
                <Link :href="href(page - 1)" :class="ITEM" class="text-fg hover:bg-muted" rel="prev" data-test="previous">
                    <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            fill-rule="evenodd"
                            d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    {{ t('ui.pagination.previous') }}
                </Link>
            </li>
            <li v-for="(p, index) in pages" :key="p ?? `gap-${index}`">
                <span v-if="p === null" :class="ITEM" class="text-fg-muted" aria-hidden="true">…</span>
                <span
                    v-else-if="p === page"
                    :class="ITEM"
                    class="bg-accent text-on-accent"
                    aria-current="page"
                    :aria-label="t('ui.pagination.page', { page: formatNumber(p) })"
                    >{{ formatNumber(p) }}</span
                >
                <Link
                    v-else
                    :href="href(p)"
                    :class="ITEM"
                    class="text-fg hover:bg-muted"
                    :aria-label="t('ui.pagination.page', { page: formatNumber(p) })"
                    >{{ formatNumber(p) }}</Link
                >
            </li>
            <li v-if="page < lastPage">
                <Link :href="href(page + 1)" :class="ITEM" class="text-fg hover:bg-muted" rel="next" data-test="next">
                    {{ t('ui.pagination.next') }}
                    <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            fill-rule="evenodd"
                            d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </Link>
            </li>
        </ul>
    </nav>
</template>
