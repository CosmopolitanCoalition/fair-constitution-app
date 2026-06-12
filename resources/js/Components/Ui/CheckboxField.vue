<script setup>
/**
 * Ui/CheckboxField — .checkbox label + input, v-model on a boolean.
 * Extra attrs (name, value, disabled, …) fall through to the input.
 */
defineOptions({ inheritAttrs: false });

defineProps({
    modelValue: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <label class="checkbox">
        <input
            type="checkbox"
            v-bind="$attrs"
            :checked="modelValue"
            @change="emit('update:modelValue', $event.target.checked)"
        />
        <span><slot /></span>
    </label>
</template>
