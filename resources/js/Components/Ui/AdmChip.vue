<script setup>
/**
 * Ui/AdmChip — jurisdiction tier chip (.adm-chip--0..5 / .tier-dot--0..5).
 * Levels beyond 5 clamp to 5 for color; the title keeps the natural label
 * (numeric adm levels are development terminology and never display).
 */
import { computed } from 'vue';

/* Natural level labels (the ETL repo's vocabulary). */
const ADM_LABELS = ['Planet', 'Country', 'State / Province', 'County', 'Municipality', 'Township', 'Neighborhood'];

const props = defineProps({
    level: { type: Number, required: true },
    label: { type: String, default: '' },
    dotOnly: { type: Boolean, default: false },
    /** Defaults to the natural level label — never numeric. */
    title: { type: String, default: null },
});

const clamped = computed(() => Math.min(Math.max(Math.trunc(props.level), 0), 5));
const naturalLabel = computed(() => ADM_LABELS[Math.min(Math.max(Math.trunc(props.level), 0), 6)]);
const resolvedTitle = computed(() => props.title ?? naturalLabel.value);
</script>

<template>
    <span
        v-if="dotOnly"
        class="tier-dot"
        :class="`tier-dot--${clamped}`"
        :title="resolvedTitle"
        :role="label ? 'img' : undefined"
        :aria-label="label || undefined"
        :aria-hidden="label ? undefined : 'true'"
    />
    <span
        v-else
        class="adm-chip"
        :class="`adm-chip--${clamped}`"
        :title="resolvedTitle"
    >{{ label }}</span>
</template>
