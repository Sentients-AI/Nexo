<script setup lang="ts">
// A placeholder shape while content loads. Decorative: it is hidden from
// assistive tech, and the container that is loading should set
// aria-busy="true". The pulse is off under reduced motion.
withDefaults(
    defineProps<{
        shape?: 'line' | 'block' | 'circle';
        width?: string;
        height?: string;
    }>(),
    { shape: 'line' },
);
</script>

<template>
    <span
        aria-hidden="true"
        class="block bg-muted motion-safe:animate-pulse"
        :class="{
            'h-4 rounded-sm': shape === 'line',
            'h-24 rounded-md': shape === 'block',
            'size-10 rounded-full': shape === 'circle',
        }"
        :style="{ width, height }"
    />
</template>
