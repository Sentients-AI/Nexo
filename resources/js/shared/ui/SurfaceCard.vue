<script setup lang="ts">
// A bordered surface. With `title`, it renders a heading at `level` and is
// labelled by it, so it shows up as a named region to assistive tech.
import { useId } from 'vue';

withDefaults(
    defineProps<{
        title?: string;
        level?: 2 | 3 | 4;
        padded?: boolean;
    }>(),
    { level: 2, padded: true },
);

const headingId = useId();
</script>

<template>
    <section
        v-if="title"
        :aria-labelledby="headingId"
        class="rounded-lg border border-border bg-surface shadow-xs"
        :class="padded ? 'p-6' : ''"
    >
        <component :is="`h${level}`" :id="headingId" class="text-lg font-semibold tracking-tight text-fg">
            {{ title }}
        </component>
        <div class="mt-4"><slot /></div>
        <div v-if="$slots.footer" class="mt-6 border-t border-border-subtle pt-4"><slot name="footer" /></div>
    </section>
    <div v-else class="rounded-lg border border-border bg-surface shadow-xs" :class="padded ? 'p-6' : ''">
        <slot />
        <div v-if="$slots.footer" class="mt-6 border-t border-border-subtle pt-4"><slot name="footer" /></div>
    </div>
</template>
