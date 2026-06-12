<script setup>
/**
 * Ui/RadioGroup — .radio-group of .radio options, v-model on the value.
 * options: [{ value, label, disabled? }]
 */
import { computed, useId } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number, Boolean], default: null },
    options: { type: Array, required: true },
    name: { type: String, default: null },
    label: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const uid = useId();
const groupName = computed(() => props.name ?? `radio-${uid}`);
</script>

<template>
    <div class="radio-group" role="radiogroup" :aria-label="label || undefined">
        <label v-for="option in options" :key="String(option.value)" class="radio">
            <input
                type="radio"
                :name="groupName"
                :value="option.value"
                :checked="modelValue === option.value"
                :disabled="option.disabled || undefined"
                @change="emit('update:modelValue', option.value)"
            />
            <span>{{ option.label }}</span>
        </label>
    </div>
</template>
