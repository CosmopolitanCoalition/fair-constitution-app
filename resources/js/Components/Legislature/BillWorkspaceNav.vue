<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    workspace: { type: Object, required: true },
    active: { type: String, default: 'record' },
});
const { t, locale } = useI18n();
const text = (key) => t('c_bill.' + key);
const tones = { enacted: 'success', passed: 'success', failed: 'danger', tabled: 'neutral', withdrawn: 'neutral', on_floor: 'warning' };
const introduced = computed(() => props.workspace.introducedAt
    ? new Date(props.workspace.introducedAt).toLocaleString(locale.value === 'en-XA' ? 'en' : locale.value)
    : null);
const sections = ['text', 'votes', 'history'];
</script>

<template>
    <div class="bill-workspace">
        <div class="bill-context">
            <Link v-if="workspace.place" :href="workspace.place.href">{{ workspace.place.name }}</Link>
            <Link v-if="workspace.billsHref" :href="workspace.billsHref">{{ text('all_bills') }}</Link>
            <Link v-if="workspace.chamberHref" :href="workspace.chamberHref">{{ text('chamber') }}</Link>
        </div>
        <div class="bill-context bill-metadata">
            <StatusBadge :tone="tones[workspace.status] || 'info'">{{ t('c_bill.status_' + workspace.status, workspace.status.replaceAll('_', ' ')) }}</StatusBadge>
            <span>{{ t('c_bill.sponsor', { name: workspace.sponsor || text('unknown_member') }) }}</span>
            <span>{{ t('c_bill.type_' + workspace.actType, workspace.actType.replaceAll('_', ' ')) }}</span>
            <time v-if="introduced" :datetime="workspace.introducedAt">{{ t('c_bill.introduced_at', { date: introduced }) }}</time>
        </div>
        <nav class="bill-tabs" :aria-label="text('navigation')">
            <Link :href="workspace.recordHref" :aria-current="active === 'record' ? 'page' : undefined">{{ text('record') }}</Link>
            <Link :href="workspace.discussionHref" :aria-current="active === 'discussion' ? 'page' : undefined">{{ text('discussion') }}</Link>
        </nav>
        <nav class="bill-sections" :aria-label="text('record_sections')">
            <template v-for="section in sections" :key="section">
                <a v-if="active === 'record'" :href="'#bill-' + section">{{ text(section) }}</a>
                <Link v-else :href="workspace.recordHref + '#bill-' + section">{{ text(section) }}</Link>
            </template>
        </nav>
    </div>
</template>

<style scoped>
.bill-workspace { min-width: 0; }
.bill-context { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; }
.bill-context a, .bill-sections a { display: inline-flex; align-items: center; min-height: 2.75rem; }
.bill-metadata { color: var(--gov-fg-muted); font-size: var(--text-sm); margin-block: .5rem .85rem; }
.bill-tabs { display: flex; flex-wrap: wrap; gap: .4rem; border-bottom: 1px solid var(--gov-border); padding-block-end: .5rem; }
.bill-tabs a { display: inline-flex; align-items: center; min-height: 2.75rem; padding: .55rem 1rem; border-radius: var(--radius-md); text-decoration: none; color: inherit; }
.bill-tabs a[aria-current="page"] { background: var(--gov-surface-2); box-shadow: inset 0 0 0 1px var(--gov-border); font-weight: 700; }
.bill-sections { display: flex; flex-wrap: wrap; gap: .25rem 1.2rem; font-size: var(--text-sm); }
.bill-workspace a:focus-visible { outline: 3px solid var(--gov-accent, #a57924); outline-offset: 3px; }
</style>
