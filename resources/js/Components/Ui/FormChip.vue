<script setup>
/**
 * Ui/FormChip — canonical-form chip: NAME first, ID second, everywhere
 * (MANIFEST §1) — the workbook ID drift can never mislead a reader.
 * `alias` renders as "· catalog: F-XXX-0xx" when the catalog ID drifted.
 */
defineProps({
    /** Canonical form ID, e.g. 'F-IND-003'. */
    formId: { type: String, required: true },
    /** Human form name; resolved from the registry by callers when omitted. */
    name: { type: String, default: null },
    /** Drifted catalog ID, shown for honest cross-reference. */
    alias: { type: String, default: null },
});
</script>

<template>
    <span class="form-chip">
        <template v-if="name">{{ name }} </template>
        <span class="form-id">{{ formId }}</span>
        <span v-if="alias" class="form-id">· catalog: {{ alias }}</span>
    </span>
</template>
