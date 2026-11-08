<script setup lang="ts">
// A text-like input. Inside a <FormField> it takes its id,
// aria-describedby, aria-invalid and required from the field. Email, URL and
// phone values are always left to right, so "aisyah@" does not render as
// "@aisyah" on Arabic pages.
import { computed } from 'vue';
import { CONTROL_CLASSES, controlBorder } from './control-classes';
import { useFieldContext } from './field-context';

const props = withDefaults(
    defineProps<{
        type?: 'text' | 'email' | 'tel' | 'url' | 'search' | 'password' | 'number';
        placeholder?: string;
        autocomplete?: string;
        disabled?: boolean;
    }>(),
    { type: 'text', disabled: false },
);

const model = defineModel<string>({ default: '' });
const field = useFieldContext();
const invalid = computed(() => field?.invalid.value ?? false);
const LTR_TYPES = new Set(['email', 'url', 'tel']);
const dir = computed(() => (LTR_TYPES.has(props.type) ? 'ltr' : undefined));
</script>

<template>
    <input
        :id="field?.controlId"
        v-model="model"
        :type="type"
        :dir="dir"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :disabled="disabled"
        :required="field?.required.value || undefined"
        :aria-invalid="invalid || undefined"
        :aria-describedby="field?.describedBy.value"
        :class="[CONTROL_CLASSES, controlBorder(invalid)]"
    />
</template>
