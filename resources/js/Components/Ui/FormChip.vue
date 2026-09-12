<script setup>
/**
 * Ui/FormChip — canonical action name for players. Technical identifiers
 * are opt-in for reference disclosures; submitted form IDs stay unchanged.
 */
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { referenceLabel } from '@/lib/referenceLabels.js';

const props = defineProps({
    /** Canonical form ID, e.g. 'F-IND-003'. */
    formId: { type: String, required: true },
    /** Human form name; resolved from the registry by callers when omitted. */
    name: { type: String, default: null },
    /** Drifted catalog ID, shown for honest cross-reference. */
    alias: { type: String, default: null },
    /** Opt in only inside a reference disclosure. Player labels omit codes. */
    showReference: { type: Boolean, default: false },
});
const surface = inject('cga:surface', null);
const { t } = useI18n();
const label = computed(() => referenceLabel(props.formId, {
    name: props.name ?? surface?.value?.forms?.find((form) => form.id === props.formId)?.name,
    translate: (key, fallback) => t(key, fallback),
}));
</script>

<template>
    <span class="form-chip">
        {{ label }}
        <span v-if="showReference" class="form-id"> · {{ formId }}<template v-if="alias"> · {{ alias }}</template></span>
    </span>
</template>
