<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import LegislatureWorkspaceNav from '@/Components/Legislature/LegislatureWorkspaceNav.vue';
import ArchivePager from '@/Components/Legislature/ArchivePager.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();
defineProps({ legislature: Object, workspace: Object, session: Object, attendance: Object, motions: Object, records: Object, selectedMotion: Object, casts: Object });
const date = value => value ? localeFmt.dateTime(new Date(value)) : '—';
const label = value => String(value ?? '').replaceAll('_', ' ');
</script>

<template>
    <Head :title="t('c_legislature_pages_b.session_record.head_title', { no: session.session_no })" />
    <div class="stack record-page">
        <LegislatureWorkspaceNav :workspace="workspace" active="session" />
        <header>
            <h1>{{ t('c_legislature_pages_b.session_record.heading', { no: session.session_no }) }}</h1>
            <p>{{ legislature.name }} · {{ label(session.status) }}</p>
            <p>{{ t('c_legislature_pages_b.session_record.opened_adjourned', { opened: date(session.opened_at), adjourned: date(session.adjourned_at) }) }}</p>
            <p role="status">{{ t('c_legislature_pages_b.session_record.readonly_note', 'Read-only session record. Live actions are available in the current session workspace.') }}</p>
        </header>
        <nav class="cluster" :aria-label="t('c_legislature_pages_b.session_record.nav_aria', 'Session record navigation')">
            <Link :href="`/legislatures/${legislature.id}/sessions`">{{ t('c_legislature_pages_b.session_record.all_sessions', 'All sessions') }}</Link>
            <Link :href="workspace.session">{{ t('c_legislature_pages_b.session_record.current_workspace', 'Current session workspace') }}</Link>
            <a href="#agenda">{{ t('c_legislature_pages_b.session_record.agenda', 'Agenda') }}</a><a href="#attendance">{{ t('c_legislature_pages_b.session_record.attendance', 'Attendance') }}</a><a href="#motions">{{ t('c_legislature_pages_b.session_record.motions', 'Motions') }}</a><a href="#records">{{ t('c_legislature_pages_b.session_record.published_records', 'Published records') }}</a>
        </nav>
        <section id="agenda" class="card">
            <h2>{{ t('c_legislature_pages_b.session_record.agenda', 'Agenda') }}</h2>
            <p v-if="!session.agenda?.length">{{ t('c_legislature_pages_b.session_record.no_agenda', 'No agenda items were recorded.') }}</p>
            <ol v-else><li v-for="(item, index) in session.agenda" :key="index">
                {{ item.title || label(item.kind) }} <span v-if="item.status">· {{ label(item.status) }}</span>
                <Link v-if="item.ref_type === 'bill' && item.ref_id" :href="`/bills/${item.ref_id}`">{{ t('c_legislature_pages_b.session_record.view_bill', 'View bill') }}</Link>
            </li></ol>
        </section>
        <section id="attendance" class="card">
            <h2>{{ t('c_legislature_pages_b.session_record.attendance', 'Attendance') }}</h2>
            <p>{{ t('c_legislature_pages_b.session_record.quorum_line', { met: session.quorum_met === null ? t('c_legislature_pages_b.session_record.quorum_not_published', 'not published') : session.quorum_met ? t('c_legislature_pages_b.session_record.quorum_met', 'met') : t('c_legislature_pages_b.session_record.quorum_not_met', 'not met'), required: session.quorum_required ?? '—', serving: session.serving_at_open ?? '—' }) }}</p>
            <p v-if="!attendance.data.length">{{ t('c_legislature_pages_b.session_record.no_attendance', 'No attendance entries were recorded.') }}</p>
            <ul><li v-for="row in attendance.data" :key="row.id">{{ row.name }} <span v-if="row.seat">· {{ t('c_legislature_pages_b.session_record.seat', { n: row.seat }) }}</span> · {{ label(row.status) }}</li></ul>
            <ArchivePager :page="attendance" :label="t('c_legislature_pages_b.session_record.attendance_pages', 'Attendance pages')" />
        </section>
        <section id="motions" class="card">
            <h2>{{ t('c_legislature_pages_b.session_record.motions', 'Motions') }}</h2>
            <p v-if="!motions.data.length">{{ t('c_legislature_pages_b.session_record.no_motions', 'No motions were recorded.') }}</p>
            <article v-for="motion in motions.data" :key="motion.id" class="record-entry">
                <h3>{{ motion.text }}</h3><p>{{ label(motion.kind) }} · {{ label(motion.status) }} · {{ t('c_legislature_pages_b.session_record.moved_by', { name: motion.name }) }}</p>
                <Link v-if="motion.bill_id" :href="`/bills/${motion.bill_id}`">{{ t('c_legislature_pages_b.session_record.related_bill', 'Related bill') }}</Link>
                <VoteTally v-if="motion.vote" :mode="motion.vote.mode" :stage="motion.vote.stage"
                    :threshold-class="motion.vote.thresholdClass" :serving="motion.vote.serving"
                    :required-yes="motion.vote.requiredYes" :tallies="motion.vote.tallies" :quorum="motion.vote.quorum"
                    :kinds="motion.vote.kinds" :outcome="motion.vote.outcome" :speaker-tiebreak="motion.vote.speakerTiebreak" :can-cast="false" />
                <Link v-if="motion.castsHref" :href="motion.castsHref">{{ t('c_legislature_pages_b.session_record.browse_votes', 'Browse published votes') }}</Link>
            </article>
            <ArchivePager :page="motions" :label="t('c_legislature_pages_b.session_record.motion_pages', 'Motion pages')" />
        </section>
        <section v-if="selectedMotion" id="casts" class="card">
            <h2>{{ t('c_legislature_pages_b.session_record.published_votes', 'Published votes') }}</h2><p>{{ selectedMotion.text }}</p>
            <p v-if="!casts?.data.length">{{ t('c_legislature_pages_b.session_record.no_votes', 'No votes have been filed for this motion.') }}</p>
            <article v-for="cast in casts?.data ?? []" :key="cast.id" class="record-entry">
                <p>{{ cast.name }} · {{ label(cast.value) }} <span v-if="cast.isTiebreak">· {{ t('c_legislature_pages_b.session_record.tiebreak', 'tie-breaking vote') }}</span></p>
                <p v-if="cast.explanation" class="record-body">{{ cast.explanation }}</p>
            </article>
            <ArchivePager v-if="casts" :page="casts" :label="t('c_legislature_pages_b.session_record.vote_pages', 'Published vote pages')" />
        </section>
        <section id="records" class="card">
            <h2>{{ t('c_legislature_pages_b.session_record.records_heading', 'Published statements and minutes') }}</h2>
            <p v-if="!records.data.length">{{ t('c_legislature_pages_b.session_record.no_records', 'No public records have been filed for this session.') }}</p>
            <details v-for="record in records.data" :key="record.id" class="record-entry">
                <summary>{{ record.title || label(record.kind) }} · {{ date(record.date) }}</summary>
                <p class="record-body">{{ record.body }}</p>
                <Link v-if="record.auditHref" :href="record.auditHref">{{ t('c_legislature_pages_b.session_record.audit_entry', 'Audit-chain entry') }}</Link>
            </details>
            <ArchivePager :page="records" :label="t('c_legislature_pages_b.session_record.record_pages', 'Public record pages')" />
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
