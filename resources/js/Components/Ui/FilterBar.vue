<script setup>
/**
 * Ui/FilterBar — .filter-bar container for ChipToggles / TagChips / fields
 * (registries, the open ballot).
 */
defineProps({
    label: { type: String, default: null },
});
</script>

<template>
    <div class="filter-bar" role="group" :aria-label="label || undefined">
        <slot />
    </div>
</template>
