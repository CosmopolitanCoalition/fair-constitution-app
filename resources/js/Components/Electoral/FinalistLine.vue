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
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    /** X — finalist places. */
    count: { type: Number, required: true },
    label: { type: String, default: null },
});

const text = computed(
    () =>
        props.label ??
        t('c_institution_components.finalist_line.text', 'finalist line — top {count} advance to the ranked ballot · CLK-21', { named: { count: props.count } }),
);
</script>

<template>
    <div class="finalist-line" role="separator" :aria-label="t('c_institution_components.finalist_line.aria', 'Finalist line')">{{ text }}</div>
</template>
