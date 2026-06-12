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
defineProps({
    /** v-model:pressed */
    pressed: { type: Boolean, required: true },
    /** a11y name. */
    candidateName: { type: String, required: true },
    /** phase ≠ approval OR viewer < R-04. */
    disabled: { type: Boolean, default: false },
    /** → title attr (mockup verbatim). */
    disabledReason: { type: String, default: 'Approval phase is closed' },
    /** in-flight POST. */
    busy: { type: Boolean, default: false },
    labels: { type: Object, default: () => ({ off: 'Approve', on: 'Approved' }) },
});

const emit = defineEmits(['update:pressed']);
</script>

<template>
    <button
        type="button"
        class="switch"
        :aria-pressed="String(pressed)"
        :disabled="disabled || busy"
        :title="disabled ? disabledReason : null"
        :aria-label="(pressed ? 'Withdraw approval for ' : 'Approve ') + candidateName"
        @click="emit('update:pressed', !pressed)"
    >{{ pressed ? labels.on : labels.off }}</button>
</template>
