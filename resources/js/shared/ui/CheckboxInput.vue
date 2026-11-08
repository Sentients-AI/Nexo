<script setup lang="ts">
// A checkbox with its own label (a checkbox's label sits beside it, so it
// does not use <Field>). Native input: Space toggles, label click toggles.
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        hint?: string;
        error?: string;
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const model = defineModel<boolean>({ default: false });
const id = useId();
const describedBy = computed(
    () => [props.error ? `${id}-error` : null, props.hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
    <div class="flex items-start gap-3">
        <input
            :id="id"
            v-model="model"
            type="checkbox"
            :disabled="disabled"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="describedBy"
            class="mt-0.5 size-5 shrink-0 cursor-pointer rounded-sm border-border-control accent-accent disabled:cursor-not-allowed"
        />
        <div class="min-w-0">
            <label :for="id" class="cursor-pointer text-sm font-medium text-fg">{{ label }}</label>
            <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-danger">{{ error }}</p>
            <p v-if="hint" :id="`${id}-hint`" class="mt-1 text-sm text-fg-muted">{{ hint }}</p>
        </div>
    </div>
</template>
