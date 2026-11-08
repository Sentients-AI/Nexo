<script setup lang="ts">
// Shown when a list or page has nothing to display, with an optional next
// step in the `action` slot and a decorative icon in the `icon` slot.
withDefaults(defineProps<{ title: string; description?: string; level?: 2 | 3 | 4 }>(), { level: 2 });
</script>

<template>
    <div class="flex flex-col items-center px-6 py-12 text-center">
        <div v-if="$slots.icon" class="mb-4 text-fg-muted" aria-hidden="true"><slot name="icon" /></div>
        <component :is="`h${level}`" class="text-lg font-semibold text-fg">{{ title }}</component>
        <p v-if="description" class="mt-2 max-w-md text-fg-muted">{{ description }}</p>
        <div v-if="$slots.action" class="mt-6"><slot name="action" /></div>
    </div>
</template>
