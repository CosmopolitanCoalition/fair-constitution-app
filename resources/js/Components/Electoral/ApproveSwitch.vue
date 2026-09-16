<script setup>
/**
 * Electoral/ApproveSwitch — the revocable approval toggle (.switch).
 * PHASE_B_DESIGN_frontend.md §A.1.
 *
 * Open-ballot contract: revocable any time during the approval phase;
 * disabled when phase ≠ approval. Never color-only — the CSS contract
 * changes the label text too ("Approve" ↔ "Approved").
 *
 * The switch only emits; the PARENT owns the POST/DELETE + optimistic
 * revert, and announces state changes through useAnnounce() after server
 * ack ("Approved — revocable" / "Approval withdrawn").
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    /** v-model:pressed */
    pressed: { type: Boolean, required: true },
    /** a11y name. */
    candidateName: { type: String, required: true },
    /** phase ≠ approval OR viewer < R-04. */
    disabled: { type: Boolean, default: false },
    /** → title attr. Parent may override; else the localized default. */
    disabledReason: { type: String, default: null },
    /** in-flight POST. */
    busy: { type: Boolean, default: false },
    /** Parent may override; else the localized default labels. */
    labels: { type: Object, default: null },
});

const emit = defineEmits(['update:pressed']);

// Displayed label follows the parent override or the localized default.
const shownLabel = computed(() =>
    props.pressed
        ? props.labels?.on ?? t('c_institution_components.approve_switch.default_on', 'Approved')
        : props.labels?.off ?? t('c_institution_components.approve_switch.default_off', 'Approve'),
);
const shownTitle = computed(() =>
    props.disabled
        ? props.disabledReason ?? t('c_institution_components.approve_switch.disabled_reason', 'Approval phase is closed')
        : null,
);
</script>

<template>
    <button
        type="button"
        class="switch"
        :aria-pressed="String(pressed)"
        :disabled="disabled || busy"
        :title="shownTitle"
        :aria-label="pressed ? t('c_institution_components.approve_switch.withdraw_aria', 'Withdraw approval for {name}', { named: { name: candidateName } }) : t('c_institution_components.approve_switch.approve_aria', 'Approve {name}', { named: { name: candidateName } })"
        @click="emit('update:pressed', !pressed)"
    >{{ shownLabel }}</button>
</template>
