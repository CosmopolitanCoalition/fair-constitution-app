<script setup>
/**
 * Surface/AboutSurface — collapsed "About this surface" disclosure: workflow
 * links, entity state machines, and the constitutional citation line.
 * Content arrives via the default slot (prose) + `citation`.
 */
import CitationLine from '@/Components/Ui/CitationLine.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { useI18n } from 'vue-i18n';

defineProps({
    citation: { type: String, default: null },
    summaryLabel: { type: String, default: null },
    open: { type: Boolean, default: false },
});
const { t } = useI18n();
</script>

<template>
    <details class="about-surface" :open="open">
        <summary>
            <Icon name="chevron-right" size="sm" />
            {{ summaryLabel ?? t('c_references.how_page_works', 'How this page works') }}
        </summary>
        <div class="about-surface-body">
            <slot />
            <CitationLine v-if="citation" :text="citation" />
        </div>
    </details>
</template>
