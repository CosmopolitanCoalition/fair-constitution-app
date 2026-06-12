<script setup>
/**
 * Ui/ChipToggle — pressed-state filter chip (.chip-toggle[aria-pressed]).
 * Usage: <ChipToggle v-model:pressed="active">Active</ChipToggle>
 */
defineProps({
    pressed: { type: Boolean, default: false },
});

const emit = defineEmits(['update:pressed']);
</script>

<template>
    <button
        type="button"
        class="chip-toggle"
        :aria-pressed="String(pressed)"
        @click="emit('update:pressed', !pressed)"
    >
        <slot />
    </button>
</template>
