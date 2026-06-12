<script setup>
/**
 * Ui/Stat — big tabular number with quiet label; `accent` paints it gold.
 */
defineProps({
    value: { type: [String, Number], required: true },
    label: { type: String, required: true },
    accent: { type: Boolean, default: false },
});
</script>

<template>
    <div class="stat" :class="{ 'stat--accent': accent }">
        <span class="stat-number">{{ value }}</span>
        <span class="stat-label">{{ label }}</span>
    </div>
</template>
