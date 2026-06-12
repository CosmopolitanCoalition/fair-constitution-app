<script setup>
/**
 * Ui/CitationLine — the signature voice move: constitutional source in mono.
 * `implemented` appends an honest "as implemented" link to the constitutional
 * questions ledger (use `anchor` for the specific entry).
 */
defineProps({
    text: { type: String, required: true },
    /** The implementation intentionally supersedes the cited mechanism. */
    implemented: { type: Boolean, default: false },
    /** Ledger href, e.g. '/system/constitutional-questions#q2'. */
    anchor: { type: String, default: null },
});
</script>

<template>
    <span class="citation" :class="{ 'citation--implemented': implemented }">
        {{ text }}<template v-if="implemented">
            ·
            <a :href="anchor || '#'">as implemented</a>
        </template>
    </span>
</template>
