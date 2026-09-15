<script setup>
/**
 * Ui/CitationLine — the signature voice move: constitutional source in mono.
 * `implemented` appends an honest "as implemented" link to the constitutional
 * questions ledger (use `anchor` for the specific entry).
 */
import { useI18n } from 'vue-i18n';
import ReferenceText from './ReferenceText.vue';

const { t } = useI18n();

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
            <a :href="anchor || '#'">{{ t('c_ui_a.citation_line.as_implemented', 'as implemented') }}</a>
        </template>
    </span>
</template>
