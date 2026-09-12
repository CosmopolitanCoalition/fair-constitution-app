<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import LegislatureWorkspaceNav from '@/Components/Legislature/LegislatureWorkspaceNav.vue';
import ArchivePager from '@/Components/Legislature/ArchivePager.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
defineOptions({ layout: AppShellV2 });
defineProps({ legislature: Object, workspace: Object, session: Object, attendance: Object, motions: Object, records: Object, selectedMotion: Object, casts: Object });
const date = value => value ? new Date(value).toLocaleString() : '—';
const label = value => String(value ?? '').replaceAll('_', ' ');
</script>

<template>
    <Head :title="`Session ${session.session_no} record`" />
    <div class="stack record-page">
        <LegislatureWorkspaceNav :workspace="workspace" active="session" />
        <header>
            <h1>Session {{ session.session_no }} — record</h1>
            <p>{{ legislature.name }} · {{ label(session.status) }}</p>
            <p>Opened {{ date(session.opened_at) }} · adjourned {{ date(session.adjourned_at) }}</p>
            <p role="status">Read-only session record. Live actions are available in the current session workspace.</p>
        </header>
        <nav class="cluster" aria-label="Session record navigation">
            <Link :href="`/legislatures/${legislature.id}/sessions`">All sessions</Link>
            <Link :href="workspace.session">Current session workspace</Link>
            <a href="#agenda">Agenda</a><a href="#attendance">Attendance</a><a href="#motions">Motions</a><a href="#records">Published records</a>
        </nav>
        <section id="agenda" class="card">
            <h2>Agenda</h2>
            <p v-if="!session.agenda?.length">No agenda items were recorded.</p>
            <ol v-else><li v-for="(item, index) in session.agenda" :key="index">
                {{ item.title || label(item.kind) }} <span v-if="item.status">· {{ label(item.status) }}</span>
                <Link v-if="item.ref_type === 'bill' && item.ref_id" :href="`/bills/${item.ref_id}`">View bill</Link>
            </li></ol>
        </section>
        <section id="attendance" class="card">
            <h2>Attendance</h2>
            <p>Quorum {{ session.quorum_met === null ? 'not published' : session.quorum_met ? 'met' : 'not met' }} · required {{ session.quorum_required ?? '—' }} of {{ session.serving_at_open ?? '—' }} serving at opening.</p>
            <p v-if="!attendance.data.length">No attendance entries were recorded.</p>
            <ul><li v-for="row in attendance.data" :key="row.id">{{ row.name }} <span v-if="row.seat">· seat {{ row.seat }}</span> · {{ label(row.status) }}</li></ul>
            <ArchivePager :page="attendance" label="Attendance pages" />
        </section>
        <section id="motions" class="card">
            <h2>Motions</h2>
            <p v-if="!motions.data.length">No motions were recorded.</p>
            <article v-for="motion in motions.data" :key="motion.id" class="record-entry">
                <h3>{{ motion.text }}</h3><p>{{ label(motion.kind) }} · {{ label(motion.status) }} · moved by {{ motion.name }}</p>
                <Link v-if="motion.bill_id" :href="`/bills/${motion.bill_id}`">Related bill</Link>
                <VoteTally v-if="motion.vote" :mode="motion.vote.mode" :stage="motion.vote.stage"
                    :threshold-class="motion.vote.thresholdClass" :serving="motion.vote.serving"
                    :required-yes="motion.vote.requiredYes" :tallies="motion.vote.tallies" :quorum="motion.vote.quorum"
                    :kinds="motion.vote.kinds" :outcome="motion.vote.outcome" :speaker-tiebreak="motion.vote.speakerTiebreak" :can-cast="false" />
                <Link v-if="motion.castsHref" :href="motion.castsHref">Browse published votes</Link>
            </article>
            <ArchivePager :page="motions" label="Motion pages" />
        </section>
        <section v-if="selectedMotion" id="casts" class="card">
            <h2>Published votes</h2><p>{{ selectedMotion.text }}</p>
            <p v-if="!casts?.data.length">No votes have been filed for this motion.</p>
            <article v-for="cast in casts?.data ?? []" :key="cast.id" class="record-entry">
                <p>{{ cast.name }} · {{ label(cast.value) }} <span v-if="cast.isTiebreak">· tie-breaking vote</span></p>
                <p v-if="cast.explanation" class="record-body">{{ cast.explanation }}</p>
            </article>
            <ArchivePager v-if="casts" :page="casts" label="Published vote pages" />
        </section>
        <section id="records" class="card">
            <h2>Published statements and minutes</h2>
            <p v-if="!records.data.length">No public records have been filed for this session.</p>
            <details v-for="record in records.data" :key="record.id" class="record-entry">
                <summary>{{ record.title || label(record.kind) }} · {{ date(record.date) }}</summary>
                <p class="record-body">{{ record.body }}</p>
                <Link v-if="record.auditHref" :href="record.auditHref">Audit-chain entry</Link>
            </details>
            <ArchivePager :page="records" label="Public record pages" />
        </section>
    </div>
</template>

<style scoped>
.record-page { max-inline-size: 80rem; margin-inline: auto; padding: var(--space-4); }
.record-page section { scroll-margin-block-start: 8rem; }
.record-page a, .record-page summary { display: inline-flex; align-items: center; min-block-size: 44px; }
.record-entry { border-block-start: 1px solid var(--gov-border); padding-block: 1rem; }
.record-body { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
