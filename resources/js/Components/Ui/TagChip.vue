<script setup>
/**
 * Ui/TagChip — quiet informational tag (.tag-chip).
 */
defineProps({
    text: { type: String, default: null },
});
</script>

<template>
    <span class="tag-chip"><slot>{{ text }}</slot></span>
</template>
