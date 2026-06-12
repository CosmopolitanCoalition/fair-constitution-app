<script setup>
/**
 * Ui/LogRow — one append-only log entry (audit chain, sync log, public
 * records). Rejected entries stay on the record — append-only means the
 * rejection itself is appended.
 */
defineProps({
    seq: { type: [Number, String], required: true },
    hash: { type: String, default: null },
    rejected: { type: Boolean, default: false },
});
</script>

<template>
    <div class="log-row" :class="{ 'log-row--rejected': rejected }">
        <span class="log-seq">#{{ seq }}</span>
        <slot />
        <span v-if="hash" class="log-hash">{{ hash }}</span>
    </div>
</template>
