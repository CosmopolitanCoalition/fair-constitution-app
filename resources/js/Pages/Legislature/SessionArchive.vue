<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import LegislatureWorkspaceNav from '@/Components/Legislature/LegislatureWorkspaceNav.vue';
import ArchivePager from '@/Components/Legislature/ArchivePager.vue';
defineOptions({ layout: AppShellV2 });
defineProps({ legislature: Object, workspace: Object, sessions: Object });
const { t } = useI18n();
const date = value => value ? localeFmt.dateTime(new Date(value)) : t('c_legislature_workspace.session_archive.not_scheduled', 'Not scheduled');
</script>

<template>
    <Head :title="t('c_legislature_workspace.session_archive.title', 'Session archive')" />
    <div class="stack archive-page">
        <LegislatureWorkspaceNav :workspace="workspace" active="session" />
        <header><h1>{{ t('c_legislature_workspace.session_archive.title', 'Session archive') }}</h1><p>{{ legislature.name }} · {{ t('c_legislature_workspace.session_archive.summary', 'agendas, attendance, motions and published records.') }}</p></header>
        <Link :href="workspace.session">{{ t('c_legislature_workspace.session_archive.current_workspace', 'Current session workspace') }}</Link>
        <section class="card" :aria-label="t('c_legislature_workspace.session_archive.sessions_label', 'Sessions')">
            <p v-if="!sessions.data.length">{{ t('c_legislature_workspace.session_archive.none', 'No sessions have been recorded for this legislature.') }}</p>
            <article v-for="session in sessions.data" :key="session.id" class="archive-session">
                <h2><Link :href="session.href">{{ t('c_legislature_workspace.session_archive.session', 'Session') }} {{ session.number }}</Link></h2>
                <p>{{ date(session.date) }} · {{ session.status.replaceAll('_', ' ') }}</p>
            </article>
            <ArchivePager :page="sessions" :label="t('c_legislature_workspace.session_archive.pager_label', 'Session archive pages')" />
        </section>
    </div>
</template>

<style scoped>
.archive-page { max-inline-size: 72rem; margin-inline: auto; padding: var(--space-4); }
.archive-session { border-block-end: 1px solid var(--gov-border); padding-block: 1rem; }
.archive-session a { display: inline-flex; align-items: center; min-block-size: 44px; }
</style>
