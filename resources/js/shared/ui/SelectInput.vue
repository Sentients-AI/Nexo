<script setup lang="ts">
// Native <select>: keyboard, screen-reader and mobile behaviour for free.
import { computed } from 'vue';
import { CONTROL_CLASSES, controlBorder } from './control-classes';
import { useFieldContext } from './field-context';

export interface SelectOption {
    value: string;
    label: string;
    disabled?: boolean;
}

withDefaults(
    defineProps<{
        options: readonly SelectOption[];
        /** Shown as an empty, unselectable first option. */
        placeholder?: string;
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const model = defineModel<string>({ default: '' });
const field = useFieldContext();
const invalid = computed(() => field?.invalid.value ?? false);
</script>

<template>
    <div class="relative">
        <select
            :id="field?.controlId"
            v-model="model"
            :disabled="disabled"
            :required="field?.required.value || undefined"
            :aria-invalid="invalid || undefined"
            :aria-describedby="field?.describedBy.value"
            :class="[CONTROL_CLASSES, controlBorder(invalid), 'appearance-none pe-10']"
        >
            <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">
                {{ option.label }}
            </option>
        </select>
        <svg
            class="pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2 text-fg-muted"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path
                fill-rule="evenodd"
                d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"
                clip-rule="evenodd"
            />
        </svg>
    </div>
</template>
