<script setup>
/**
 * Ui/EngineChip — dashed mono chip naming a hardened engine
 * (e.g. "Countback engine (hardened)").
 */
defineProps({
    text: { type: String, default: null },
});
</script>

<template>
    <span class="engine-chip"><slot>{{ text }}</slot></span>
</template>
