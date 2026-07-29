<script setup>
/**
 * Shell/AppFooter — .app-footer with the page's constitutional citation,
 * instance identity, the audit-chain chip, and the site-wide help links
 * (V3 synthesis S5, per the mockup footer: Accessibility + Report an issue
 * carrying ?ref=<surface> so the intake knows which screen it came from).
 *
 * The surface is INJECTED (both shells `provide('cga:surface', …)`) rather
 * than threaded as a prop, so the links ride every page of both shells with
 * no shell edits. Accessibility renders as Planned until its page lands
 * (Wave 2) — the registry idiom: never a dead link.
 */
import { computed, inject } from 'vue';
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

/* Which screen a report arrives from. Surface id when the page ships one;
   the path otherwise — the intake wants a hint, not a guarantee. */
const surface = inject('cga:surface', null);
const reportHref = computed(() => {
    const ref =
        surface?.value?.id ??
        (typeof window !== 'undefined' ? window.location.pathname : 'page');
    return `/support/report?ref=${encodeURIComponent(ref)}`;
});
</script>

<template>
    <footer class="app-footer">
        <span v-if="citation" class="footer-citation">{{ citation }}</span>
        <span class="header-spacer"></span>
        <slot />
        <!-- Accessibility statement page is Wave 2; flips to a link when it lands. -->
        <span class="citation" title="The accessibility statement page is on its way">
            Accessibility <span class="planned-flag">Planned</span>
        </span>
        <a :href="reportHref"><Icon name="flag" size="sm" /> Report an issue</a>
        <span class="footer-instance">{{ instanceLine }}</span>
        <span v-if="auditSeq !== null" class="audit-chip">
            {{ t('footer.audit', { n: auditSeq.toLocaleString() }) }}
            <Icon name="check" size="sm" label="verified" />
        </span>
    </footer>
</template>
