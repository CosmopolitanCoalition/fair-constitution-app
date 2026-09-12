<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import LegislatureWorkspaceNav from '@/Components/Legislature/LegislatureWorkspaceNav.vue';
import ArchivePager from '@/Components/Legislature/ArchivePager.vue';
defineOptions({ layout: AppShellV2 });
defineProps({ legislature: Object, workspace: Object, sessions: Object });
const date = value => value ? new Date(value).toLocaleString() : 'Not scheduled';
</script>

<template>
    <Head title="Session archive" />
    <div class="stack archive-page">
        <LegislatureWorkspaceNav :workspace="workspace" active="session" />
        <header><h1>Session archive</h1><p>{{ legislature.name }} · agendas, attendance, motions and published records.</p></header>
        <Link :href="workspace.session">Current session workspace</Link>
        <section class="card" aria-label="Sessions">
            <p v-if="!sessions.data.length">No sessions have been recorded for this legislature.</p>
            <article v-for="session in sessions.data" :key="session.id" class="archive-session">
                <h2><Link :href="session.href">Session {{ session.number }}</Link></h2>
                <p>{{ date(session.date) }} · {{ session.status.replaceAll('_', ' ') }}</p>
            </article>
            <ArchivePager :page="sessions" label="Session archive pages" />
        </section>
    </div>
</template>

<style scoped>
.archive-page { max-inline-size: 72rem; margin-inline: auto; padding: var(--space-4); }
.archive-session { border-block-end: 1px solid var(--gov-border); padding-block: 1rem; }
.archive-session a { display: inline-flex; align-items: center; min-block-size: 44px; }
</style>
