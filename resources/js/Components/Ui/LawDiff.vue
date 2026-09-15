<script setup>
/**
 * Ui/LawDiff — law version diffs (.law-diff del/ins; FE-C1,
 * PHASE_C_DESIGN_frontend.md §A.2). Markup grammar from
 * mockups/judiciary/constitutional-challenge.html lawDiff().
 *
 * The diff is computed SERVER-side (App\Support\TextDiff::segments —
 * a presenter concern): this component renders verbatim segments so what
 * citizens see is exactly what the audit chain hashed. Used by BillDetail
 * (version-to-version), Settings (old→new preview), and Phase E challenge
 * remedies (Art. IV §5 PATH C).
 *
 * del/ins carry non-color affordances in the ported CSS (strikethrough /
 * underline-register backgrounds); the visually-hidden "removed:"/"added:"
 * prefixes make the ops explicit for screen readers.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    /** [{ op: 'eq'|'del'|'ins', text }] — SERVER-computed, rendered verbatim. */
    segments: { type: Array, required: true },
    label: { type: String, default: null },
});

const { t } = useI18n();
const ariaLabel = computed(() => props.label || t('c_ui_b.law_diff.label', 'Law text changes'));
</script>

<template>
    <div class="law-diff" role="group" :aria-label="ariaLabel" data-no-i18n>
        <template v-for="(segment, i) in segments" :key="i">
            <del v-if="segment.op === 'del'"><span class="visually-hidden">{{ t('c_ui_b.law_diff.removed', 'removed: ') }}</span>{{ segment.text }}</del>
            <ins v-else-if="segment.op === 'ins'"><span class="visually-hidden">{{ t('c_ui_b.law_diff.added', 'added: ') }}</span>{{ segment.text }}</ins>
            <template v-else>{{ segment.text }}</template>
        </template>
    </div>
</template>
