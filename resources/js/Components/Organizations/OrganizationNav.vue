<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    organization: { type: Object, required: true },
    current: { type: String, required: true },
});
const base = computed(() => '/organizations/' + encodeURIComponent(props.organization.id));
const links = computed(() => [
    { key: 'overview', label: t('c_institution_components.organization_nav.overview', 'Overview'), href: base.value + (props.organization.is_cgc ? '/cgc' : '') },
    { key: 'board', label: t('c_institution_components.organization_nav.board', 'Board & elections'), href: base.value + '/board-elections' },
    { key: 'finances', label: t('c_institution_components.organization_nav.finances', 'Finances'), href: base.value + '/economy' },
    { key: 'representation', label: t('c_institution_components.organization_nav.representation', 'Worker representation'), href: '/organizations/co-determination?org=' + encodeURIComponent(props.organization.id) },
    { key: 'ownership', label: t('c_institution_components.organization_nav.ownership', 'Ownership changes'), href: '/organizations/transfers-conversions?org=' + encodeURIComponent(props.organization.id) },
]);
</script>

<template>
    <div class="org-workspace">
        <div class="org-workspace-heading">
            <Link href="/organizations">{{ t('c_institution_components.organization_nav.all_organizations', 'All organizations') }}</Link>
            <strong>{{ organization.name }}</strong>
        </div>
        <nav :aria-label="t('c_institution_components.organization_nav.workspace_aria', '{name} workspace', { name: organization.name })">
            <Link v-for="item in links" :key="item.key" :href="item.href" :aria-current="current === item.key ? 'page' : undefined">
                {{ item.label }}
            </Link>
        </nav>
    </div>
</template>

<style scoped>
.org-workspace { margin-block-end: var(--space-2); }
.org-workspace-heading { display: flex; align-items: baseline; flex-wrap: wrap; gap: var(--space-3); margin-block-end: var(--space-2); }
.org-workspace-heading strong { overflow-wrap: anywhere; }
.org-workspace-heading > a { display: inline-flex; align-items: center; min-block-size: 44px; }
.org-workspace nav { display: flex; flex-wrap: wrap; gap: .35rem; padding-block-end: var(--space-2); border-block-end: 1px solid var(--gov-border); }
.org-workspace nav a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .5rem .75rem; border: 1px solid transparent; border-radius: var(--radius-md, .5rem); color: inherit; text-decoration: none; }
.org-workspace nav a[aria-current="page"] { border-color: var(--gov-accent); background: color-mix(in oklch, var(--gov-accent) 10%, var(--gov-surface)); font-weight: 600; }
.org-workspace nav a:hover { text-decoration: underline; }
.org-workspace a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 2px; }
</style>
