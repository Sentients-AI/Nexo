<script setup lang="ts">
// Navigation. Internal URLs go through Inertia; `external` renders a plain
// anchor that opens in a new tab with rel="noopener noreferrer" and says so
// to screen readers. `appearance` lets a link look like a button.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/i18n';
import { buttonClasses, type ButtonSize, type ButtonVariant } from './button-classes';

const props = withDefaults(
    defineProps<{
        href: string;
        external?: boolean;
        appearance?: 'text' | ButtonVariant;
        size?: ButtonSize;
        /** Marks the link as the current page (navigation lists). */
        current?: boolean;
    }>(),
    { external: false, appearance: 'text', size: 'md', current: false },
);

const { t } = useI18n();

const classes = computed(() =>
    props.appearance === 'text'
        ? 'font-medium text-fg underline decoration-border-control underline-offset-4 transition-colors hover:decoration-fg'
        : buttonClasses(props.appearance, props.size),
);
</script>

<template>
    <a
        v-if="external"
        :href="href"
        :class="classes"
        target="_blank"
        rel="noopener noreferrer"
        :aria-current="current ? 'page' : undefined"
    >
        <slot />
        <span class="sr-only">{{ t('ui.link.new_tab') }}</span>
    </a>
    <Link v-else :href="href" :class="classes" :aria-current="current ? 'page' : undefined">
        <slot />
    </Link>
</template>
