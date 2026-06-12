<script setup>
/**
 * Shell/AppFooter — .app-footer with the page's constitutional citation,
 * instance identity, and the audit-chain chip. PURE presentational.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Ui/Icon.vue';

const props = defineProps({
    /** The surface's constitutional citation line (mono). */
    citation: { type: String, default: null },
    /** { host, authoritativeFor } */
    instance: { type: Object, required: true },
    /** Latest audit-chain sequence number; chip hidden when null. */
    auditSeq: { type: Number, default: null },
});

const { t } = useI18n({ useScope: 'global' });

const instanceLine = computed(
    () => `Instance: ${props.instance.host} · authoritative for ${props.instance.authoritativeFor}`,
);
</script>

<template>
    <footer class="app-footer">
        <span v-if="citation" class="footer-citation">{{ citation }}</span>
        <span class="header-spacer"></span>
        <slot />
        <span class="footer-instance">{{ instanceLine }}</span>
        <span v-if="auditSeq !== null" class="audit-chip">
            {{ t('footer.audit', { n: auditSeq.toLocaleString() }) }}
            <Icon name="check" size="sm" label="verified" />
        </span>
    </footer>
</template>
