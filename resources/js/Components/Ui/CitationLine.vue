<script setup>
/**
 * Ui/CitationLine — the signature voice move: constitutional source in mono.
 * `implemented` appends an honest "as implemented" link to the constitutional
 * questions ledger (use `anchor` for the specific entry).
 */
import ReferenceText from './ReferenceText.vue';

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
        <ReferenceText>{{ text }}</ReferenceText><template v-if="implemented">
            ·
            <a :href="anchor || '#'">as implemented</a>
        </template>
    </span>
</template>
