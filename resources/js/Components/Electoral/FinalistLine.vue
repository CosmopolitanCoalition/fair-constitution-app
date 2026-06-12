<script setup>
/**
 * Electoral/FinalistLine — the dashed gold line drawn across the standings
 * list at the finalist cutoff (.finalist-line · CLK-21).
 * PHASE_B_DESIGN_frontend.md §A.6.
 *
 * Inserted by the standings list AFTER full-race rank X regardless of
 * active filters — ranks always reflect the full race (open-ballot
 * contract).
 *
 * Placement note: the design's §A.6 heading says Ui/ (frontend-port row 26);
 * the FE-B1 work order placed all eight components under Electoral/ — this
 * file follows the work order.
 */
import { computed } from 'vue';

const props = defineProps({
    /** X — finalist places. */
    count: { type: Number, required: true },
    label: { type: String, default: null },
});

const text = computed(
    () =>
        props.label ??
        `finalist line — top ${props.count} advance to the ranked ballot · CLK-21`,
);
</script>

<template>
    <div class="finalist-line" role="separator" aria-label="Finalist line">{{ text }}</div>
</template>
