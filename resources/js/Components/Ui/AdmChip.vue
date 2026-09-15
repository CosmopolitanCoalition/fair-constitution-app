<script setup>
/**
 * Ui/AdmChip — jurisdiction tier chip (.adm-chip--0..5 / .tier-dot--0..5).
 * Levels beyond 5 clamp to 5 for color; the title keeps the natural label
 * (numeric adm levels are development terminology and never display).
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    level: { type: Number, required: true },
    label: { type: String, default: '' },
    dotOnly: { type: Boolean, default: false },
    /** Defaults to the natural level label — never numeric. */
    title: { type: String, default: null },
});

const clamped = computed(() => Math.min(Math.max(Math.trunc(props.level), 0), 5));
/* Natural level labels (the ETL repo's vocabulary). */
const naturalLabel = computed(() => {
    const i = Math.min(Math.max(Math.trunc(props.level), 0), 6);
    const labels = [
        t('c_ui_a.adm_chip.level_0', 'Planet'),
        t('c_ui_a.adm_chip.level_1', 'Country'),
        t('c_ui_a.adm_chip.level_2', 'State / Province'),
        t('c_ui_a.adm_chip.level_3', 'County'),
        t('c_ui_a.adm_chip.level_4', 'Municipality'),
        t('c_ui_a.adm_chip.level_5', 'Township'),
        t('c_ui_a.adm_chip.level_6', 'Neighborhood'),
    ];
    return labels[i];
});
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
