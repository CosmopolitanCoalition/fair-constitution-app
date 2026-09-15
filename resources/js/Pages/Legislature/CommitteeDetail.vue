<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * Legislature/CommitteeDetail — FE-C6 (PHASE_C_DESIGN_frontend.md §B.6).
 *
 * Roster (kind chips, chair gold) · meetings (F-CHR-001/002) · per-bill
 * committee votes (VoteTally committee_majority — per-kind in bicameral
 * chambers, q7 binds at committee) · refer-to-floor gate (F-CHR-003 —
 * the Btn is disabled until the committee vote passes; the ENGINE
 * independently rejects premature referral) · testimony → public record
 * (WF-LEG-08) · report filing (F-CHR-004).
 */
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import PersonaChip from '@/Components/Ui/PersonaChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    committee: { type: Object, required: true },
    meeting: { type: Object, default: null },
    meetingContext: { type: Object, default: () => ({}) },
    bills: { type: Array, default: () => [] },
    billPages: { type: Object, default: () => ({}) },
    reports: { type: Array, default: () => [] },
    reportPages: { type: Object, default: () => ({}) },
    selectedReport: { type: Object, default: null },
    testimony: { type: Array, default: () => [] },
    testimonyPages: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const page = usePage();
// Refresh every record link so alternating pagers cannot restore another list's stale cursor.
const recordPageProps = ['bills', 'billPages', 'reports', 'reportPages', 'selectedReport', 'testimonyPages'];
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

function fmt(iso) {
    return iso ? localeFmt.dateTime(new Date(iso)) : '—';
}

const bicameral = computed(() => props.committee.by_kind != null);

const BILL_TONES = {
    in_committee: 'info',
    reported: 'success',
    tabled: 'neutral',
    on_floor: 'warning',
    passed: 'success',
    enacted: 'success',
    failed: 'danger',
};

/* --------------------------------------------- meeting (F-CHR-001/002) */
const meetingForm = useForm({ scheduled_for: '', agenda: [], agenda_text: '' });
function submitMeeting() {
    meetingForm
        .transform((data) => ({
            form_id: 'F-CHR-001',
            scheduled_for: data.scheduled_for || null,
            agenda: data.agenda_text.split('\n').map((s) => s.trim()).filter(Boolean),
        }))
        .post(props.urls.meetings, { preserveScroll: true, onSuccess: () => meetingForm.reset() });
}

const agendaForm = useForm({ agenda_text: '' });
function submitAgenda() {
    agendaForm
        .transform((data) => ({
            form_id: 'F-CHR-002',
            agenda: data.agenda_text.split('\n').map((s) => s.trim()).filter(Boolean),
        }))
        .post(`/meetings/${props.meeting.id}/agenda`, { preserveScroll: true });
}

/* ----------------------------------------------- bill votes (F-LEG-005) */
const castingBill = ref(null);
function castBillVote(bill, { value, explanation }) {
    castingBill.value = bill.id;
    router.post(bill.vote.cast_url, { value, explanation }, {
        preserveScroll: true,
        onFinish: () => {
            castingBill.value = null;
        },
    });
}

/* -------------------------------------------- refer to floor (F-CHR-003) */
const referring = ref(null);
function referToFloor(bill) {
    referring.value = bill.id;
    router.post(bill.refer_url, {}, {
        preserveScroll: true,
        onFinish: () => {
            referring.value = null;
        },
    });
}

/* ------------------------------------------------- testimony (WF-LEG-08) */
const testimonyForm = useForm({ text: '' });
function submitTestimony() {
    testimonyForm.post(`/meetings/${props.meeting.id}/testimony`, {
        preserveScroll: true,
        onSuccess: () => testimonyForm.reset(),
    });
}

/* --------------------------------------------------- report (F-CHR-004) */
const reportForm = useForm({ title: '', body: '', bill_id: '' });
const selectedReportBill = ref(null);
watch(() => reportForm.bill_id, (id) => {
    selectedReportBill.value = props.bills.find(bill => bill.id === id)
        ?? (selectedReportBill.value?.id === id ? selectedReportBill.value : null);
});
const reportBillOptions = computed(() => selectedReportBill.value && !props.bills.some(bill => bill.id === selectedReportBill.value.id)
    ? [selectedReportBill.value, ...props.bills] : props.bills);
function submitReport() {
    reportForm
        .transform((data) => ({
            form_id: 'F-CHR-004',
            title: data.title,
            body: data.body,
            bill_id: data.bill_id || null,
        }))
        .post(props.urls.reports, { preserveScroll: true, onSuccess: () => reportForm.reset() });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_legislature_pages.committee_detail.title', { name: committee.name })">
        <template #intro>
            {{ t('c_legislature_pages.committee_detail.intro_base', 'Committee hearings are public record: testimony, member votes with explanations, and reports all publish (WF-SYS-03). The committee decides by majority of ALL its members — never of those present') }}<template v-if="bicameral">{{ t('c_legislature_pages.committee_detail.intro_bicameral', ', and in this bicameral chamber each seat kind must independently agree at committee stage (Art. V §3 · ledger #q7)') }}</template>.
        </template>

        <p class="cc-small">
            <a :href="committee.legislature.href">{{ t('c_legislature_pages.committee_detail.back_link', { name: committee.legislature.name }) }}</a>
        </p>
        <nav class="cluster" :aria-label="t('c_legislature_pages.committee_detail.nav_aria', 'Hearing navigation')">
            <Link v-if="urls.room" :href="urls.room" class="btn btn--secondary">{{ t('c_legislature_pages.committee_detail.open_room', 'Open this hearing’s room') }}</Link>
            <Link v-if="meetingContext.explicit" :href="urls.current" class="btn btn--ghost">{{ t('c_legislature_pages.committee_detail.current_work', 'Current committee work') }}</Link>
        </nav>
        <Banner v-if="meetingContext.readOnly" tone="info">{{ t('c_legislature_pages.committee_detail.read_only', 'You are reading a closed hearing or committee. Filing controls are unavailable in this view.') }}</Banner>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ================================== roster =================== -->
        <Card as="section">
            <template #title>
                <h2>
                    {{ t('c_legislature_pages.committee_detail.roster_seats', { n: committee.seats }) }}
                    <template v-if="committee.by_kind">
                        {{ t('c_legislature_pages.committee_detail.roster_by_kind', { a: committee.by_kind.type_a, b: committee.by_kind.type_b }) }}
                    </template>
                    <StatusBadge :tone="committee.status === 'seated' ? 'success' : 'info'">{{ committee.status }}</StatusBadge>
                </h2>
            </template>

            <template v-if="committee.members.length">
                <!-- compact seat strip — the flat .seat-dot family -->
                <div class="cluster" aria-hidden="true" style="margin-block-end: var(--space-2)">
                    <span
                        v-for="member in committee.members"
                        :key="member.member_id"
                        class="seat-dot"
                        :class="{ 'seat-dot--speaker': member.is_chair }"
                        :title="member.name"
                    ></span>
                </div>
                <div class="stack" style="gap: var(--space-1)">
                    <div v-for="member in committee.members" :key="member.member_id" class="roster-row">
                        <span class="cluster" style="gap: var(--space-2)">
                            <PersonaChip :name="member.name ?? t('c_legislature_pages.committee_detail.member_fallback', 'Member')" />
                            <TagChip v-if="member.seat_kind">{{ member.seat_kind === 'type_b' ? t('c_legislature_pages.committee_detail.type_b', 'type B') : t('c_legislature_pages.committee_detail.type_a', 'type A') }}</TagChip>
                            <StatusBadge v-if="member.is_chair" tone="warning" icon="landmark">{{ t('c_legislature_pages.committee_detail.chair_badge', 'Chair · R-12') }}</StatusBadge>
                            <StatusBadge
                                v-else-if="committee.alternate && member.member_id === committee.alternate.member_id"
                                tone="info"
                            >{{ t('c_legislature_pages.committee_detail.alternate_badge', 'Alternate · R-13') }}</StatusBadge>
                        </span>
                        <span class="citation" data-no-i18n>{{ member.assigned_via }}</span>
                    </div>
                </div>
            </template>
            <p v-else class="gloss">
                {{ t('c_legislature_pages.committee_detail.roster_empty', 'Not yet seated — placements arrive with the F-SPK-005 assignment run on the') }}
                <a :href="committee.legislature.href">{{ t('c_legislature_pages.committee_detail.committees_page', 'committees page') }}</a>.
            </p>
        </Card>

        <!-- ================================== meeting ================== -->
        <Card as="section" :title="t('c_legislature_pages.committee_detail.meeting_title', 'Meeting')">
            <template v-if="meeting">
                <p class="cc-small">
                    {{ meeting.status === 'open' ? t('c_legislature_pages.committee_detail.meeting_open', 'In session') : meeting.status === 'adjourned' ? t('c_legislature_pages.committee_detail.meeting_adjourned', 'Adjourned') : t('c_legislature_pages.committee_detail.meeting_scheduled', 'Scheduled') }} —
                    {{ fmt(meeting.scheduled_for) }} · {{ t('c_legislature_pages.committee_detail.hearings_public', 'hearings are public record.') }}
                </p>
                <ol v-if="meeting.agenda.length" class="agenda-list">
                    <li v-for="(item, i) in meeting.agenda" :key="i" class="agenda-slot">
                        <span class="flow-step-n">{{ i + 1 }}</span>
                        <span>{{ item }}</span>
                    </li>
                </ol>
                <p v-else class="gloss">{{ t('c_legislature_pages.committee_detail.no_agenda', 'No agenda yet — the chair sets it (F-CHR-002).') }}</p>

                <FormCard
                    v-if="can.setAgenda && formMeta('F-CHR-002')"
                    :form="formMeta('F-CHR-002')"
                    :inertia-form="agendaForm"
                    :submit-label="t('c_legislature_pages.committee_detail.set_agenda', 'Set agenda')"
                    @submit="submitAgenda"
                >
                    <Field
                        :label="t('c_legislature_pages.committee_detail.agenda_items', 'Agenda items (one per line)')"
                        :hint="t('c_legislature_pages.committee_detail.agenda_hint', 'Committee agendas have no engine-locked head — emergency review is a floor-session duty (Art. II §2).')"
                        :error="agendaForm.errors.agenda ?? agendaForm.errors.constitution"
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <textarea
                                :id="id"
                                v-model="agendaForm.agenda_text"
                                class="field-input"
                                rows="3"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                </FormCard>
            </template>
            <template v-else>
                <p class="gloss">{{ t('c_legislature_pages.committee_detail.no_meeting', 'No meeting scheduled.') }}</p>
                <FormCard
                    v-if="can.call && formMeta('F-CHR-001')"
                    :form="formMeta('F-CHR-001')"
                    :inertia-form="meetingForm"
                    :submit-label="t('c_legislature_pages.committee_detail.call_meeting', 'Call meeting')"
                    @submit="submitMeeting"
                >
                    <Field :label="t('c_legislature_pages.committee_detail.scheduled_for', 'Scheduled for')" :error="meetingForm.errors.scheduled_for ?? meetingForm.errors.constitution">
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="meetingForm.scheduled_for"
                                class="field-input"
                                type="datetime-local"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                    <Field :label="t('c_legislature_pages.committee_detail.agenda_items', 'Agenda items (one per line)')">
                        <template #control="{ id }">
                            <textarea :id="id" v-model="meetingForm.agenda_text" class="field-input" rows="3"></textarea>
                        </template>
                    </Field>
                </FormCard>
                <p v-else-if="!can.call" class="citation">
                    {{ t('c_legislature_pages.committee_detail.meetings_called', 'Meetings are called by the chair — or the alternate when the chair is absent (F-CHR-001 · R-12/R-13).') }}
                </p>
            </template>
        </Card>

        <!-- ================================== bills ==================== -->
        <Card as="section" :title="t('c_legislature_pages.committee_detail.bills_title', 'Bills before the committee')">
            <p v-if="meetingContext.explicit" class="gloss">{{ t('c_legislature_pages.committee_detail.bills_scope', 'These bills and reports belong to the committee as a whole. The hearing and testimony shown here belong to the selected meeting.') }}</p>
            <p v-if="!bills.length" class="gloss">{{ t('c_legislature_pages.committee_detail.bills_empty', 'No bills on this page.') }}</p>
            <p class="gloss">{{ t('c_legislature_pages.committee_detail.bills_showing', { count: bills.length }) }}</p>
            <HistoryPager cursor-key="bills_cursor" :pages="billPages" :only="recordPageProps" :first="billPages.first ?? urls.current" :label="t('c_legislature_pages.committee_detail.bill_pages', 'Committee bill pages')" />

            <div class="stack" style="gap: var(--space-3)">
                <Card v-for="bill in bills" :key="bill.id" inset>
                    <div class="cluster" style="justify-content: space-between">
                        <h3 style="font-size: var(--text-base); margin: 0">
                            <Link :href="bill.href ?? `/bills/${bill.id}`">{{ bill.title }}</Link>
                            {{ ' ' }}
                            <StatusBadge :tone="BILL_TONES[bill.status] ?? 'neutral'">{{ bill.status }}</StatusBadge>
                        </h3>
                    </div>

                    <template v-if="bill.vote">
                        <VoteTally
                            v-bind="bill.vote.tally"
                            stage="committee"
                            :can-cast="can.vote && bill.vote.open && !bill.vote.my_cast"
                            :casting="castingBill === bill.id"
                            @cast="castBillVote(bill, $event)"
                        />
                        <p v-if="bill.vote.my_cast && bill.vote.open" class="citation">
                            {{ t('c_legislature_pages.committee_detail.cast_recorded', 'Your cast is recorded — casts are immutable.') }}
                        </p>
                        <details v-if="bill.vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="cc-small" style="cursor: pointer">{{ t('c_legislature_pages.committee_detail.published_casts', { count: bill.vote.casts.length }) }}</summary>
                            <VoteCastList :casts="bill.vote.casts" :group-by-kind="bicameral" />
                        </details>
                    </template>
                    <p v-else-if="bill.status === 'in_committee'" class="gloss">
                        {{ t('c_legislature_pages.committee_detail.vote_opens', 'The committee vote opens with the referral motion\'s adoption (WF-LEG-06) — majority of ALL committee members, not those present · Art. II §4.') }}
                    </p>

                    <!-- refer-to-floor gate (F-CHR-003) ------------------ -->
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <Btn
                            v-if="can.refer"
                            variant="primary"
                            size="sm"
                            :disabled="!bill.referable || referring === bill.id"
                            :title="bill.referable
                                ? t('c_legislature_pages.committee_detail.refer_ready', 'Moves the bill to the floor and opens its floor vote')
                                : t('c_legislature_pages.committee_detail.refer_gated', 'Enabled only after the committee vote passes · F-CHR-003')"
                            @click="referToFloor(bill)"
                        >{{ t('c_legislature_pages.committee_detail.refer_btn', 'Refer to floor (F-CHR-003)') }}</Btn>
                        <span v-if="!bill.referable" class="citation">
                            {{ t('c_legislature_pages.committee_detail.refer_note', 'enabled only after the committee vote passes · F-CHR-003 — the engine independently rejects premature referral') }}
                        </span>
                        <StatusBadge v-if="bill.report" tone="success" icon="check">
                            {{ t('c_legislature_pages.committee_detail.latest_report', { when: fmt(bill.report.filed_at) }) }}
                        </StatusBadge>
                        <Link v-if="bill.report?.href" class="citation" :href="bill.report.href" preserve-state preserve-scroll>{{ t('c_legislature_pages.committee_detail.read_report', 'Read report →') }}</Link>
                    </div>
                </Card>
            </div>
            <HistoryPager cursor-key="bills_cursor" :pages="billPages" :only="recordPageProps" :first="billPages.first ?? urls.current" :label="t('c_legislature_pages.committee_detail.bill_pages', 'Committee bill pages')" />
        </Card>

        <Card id="committee-reports" as="section" :title="t('c_legislature_pages.committee_detail.reports_title', 'Published committee reports')">
            <p class="gloss">{{ t('c_legislature_pages.committee_detail.reports_gloss', 'All reports appear here, including committee-wide reports and earlier reports about the same bill.') }}</p>
            <div v-if="reports.length" class="stack">
                <article v-for="report in reports" :key="report.id">
                    <h3><Link :href="report.href" preserve-state preserve-scroll>{{ report.title }}</Link></h3>
                    <p v-if="report.excerpt" class="cc-small">{{ report.excerpt }}</p>
                    <p class="citation">
                        {{ t('c_legislature_pages.committee_detail.filed', { when: fmt(report.filed_at) }) }}
                        <template v-if="report.bill"> · <Link :href="report.bill.href">{{ report.bill.title }}</Link></template>
                        <template v-else> · {{ t('c_legislature_pages.committee_detail.report_bill_unavailable', 'Committee-wide report or associated bill unavailable') }}</template>
                    </p>
                </article>
            </div>
            <p v-else class="gloss">{{ t('c_legislature_pages.committee_detail.reports_empty', 'No reports on this page.') }}</p>
            <HistoryPager cursor-key="reports_cursor" :pages="reportPages" :only="recordPageProps" :first="reportPages.first ?? urls.current" :label="t('c_legislature_pages.committee_detail.report_pages', 'Committee report pages')" />
        </Card>
        <Card v-if="selectedReport" id="committee-report-detail" as="section" :title="selectedReport.title">
            <p class="citation">{{ t('c_legislature_pages.committee_detail.filed', { when: fmt(selectedReport.filed_at) }) }}<template v-if="selectedReport.actor"> · {{ selectedReport.actor }}</template></p>
            <p v-if="selectedReport.body" style="white-space: pre-wrap; overflow-wrap: anywhere">{{ selectedReport.body }}</p>
            <p v-else class="gloss">{{ t('c_legislature_pages.committee_detail.report_unavailable', 'The report publication is unavailable.') }}</p>
            <div class="cluster">
                <Link v-if="selectedReport.bill" :href="selectedReport.bill.href" class="btn btn--secondary">{{ t('c_legislature_pages.committee_detail.read_bill', { title: selectedReport.bill.title }) }}</Link>
                <span v-if="selectedReport.seq" class="citation">{{ t('c_legislature_pages.committee_detail.public_record', { seq: selectedReport.seq }) }}<template v-if="selectedReport.audit_seq"> · {{ t('c_legislature_pages.committee_detail.audit_entry', { seq: selectedReport.audit_seq }) }}</template></span>
                <Link :href="selectedReport.close_href" class="btn btn--ghost" preserve-state preserve-scroll>{{ t('c_legislature_pages.committee_detail.close_report', 'Close report') }}</Link>
            </div>
        </Card>

        <div class="grid-2">
            <!-- ============================== testimony ================ -->
            <section class="card" aria-labelledby="testimony-h">
                <h2 id="testimony-h">{{ meetingContext.explicit ? t('c_legislature_pages.committee_detail.testimony_hearing', 'Testimony for this hearing') : t('c_legislature_pages.committee_detail.testimony_committee', 'Committee testimony') }}</h2>
                <p class="gloss">
                    {{ t('c_legislature_pages.committee_detail.testimony_gloss', 'Hearings take testimony from any resident; entries publish verbatim to the immutable public record · WF-LEG-08 · WF-SYS-03.') }}
                </p>
                <div v-if="testimony.length" class="stack" style="gap: var(--space-1)">
                    <LogRow v-for="row in testimony" :key="row.seq" :seq="row.seq">
                        <strong>{{ row.who }}</strong> — {{ row.text }}
                        <span class="citation" style="display: block">
                            {{ fmt(row.recorded_at) }} ·
                            <a :href="row.record_href">{{ t('c_legislature_pages.committee_detail.sealed_record', 'sealed record →') }}</a>
                        </span>
                    </LogRow>
                </div>
                <p v-else class="cc-small gloss">{{ t('c_legislature_pages.committee_detail.no_testimony', 'No testimony recorded yet.') }}</p>
                <nav v-if="testimonyPages.previous || testimonyPages.next" class="cluster" :aria-label="t('c_legislature_pages.committee_detail.testimony_pages', 'Testimony pages')">
                    <Link v-if="testimonyPages.previous" :href="testimonyPages.previous" class="btn btn--ghost" preserve-scroll preserve-state>{{ t('c_legislature_pages.committee_detail.newer_testimony', 'Newer testimony') }}</Link>
                    <Link v-if="testimonyPages.next" :href="testimonyPages.next" class="btn btn--ghost" preserve-scroll preserve-state>{{ t('c_legislature_pages.committee_detail.older_testimony', 'Older testimony') }}</Link>
                </nav>

                <template v-if="meeting && can.testify">
                    <Field
                        :label="t('c_legislature_pages.committee_detail.submit_testimony', 'Submit testimony')"
                        :hint="t('c_legislature_pages.committee_detail.testimony_hint', 'Entered verbatim into the public record — testimony cannot be edited or withdrawn.')"
                        :error="testimonyForm.errors.text ?? testimonyForm.errors.constitution"
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <textarea
                                :id="id"
                                v-model="testimonyForm.text"
                                class="field-input"
                                rows="3"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                    <Btn
                        variant="secondary"
                        size="sm"
                        :disabled="testimonyForm.processing || !testimonyForm.text.trim()"
                        @click="submitTestimony"
                    >{{ t('c_legislature_pages.committee_detail.enter_record', 'Enter into the record') }}</Btn>
                </template>
                <p v-else-if="!meeting" class="citation">{{ t('c_legislature_pages.committee_detail.testimony_needs_meeting', 'Testimony attaches to a meeting — none is scheduled.') }}</p>
            </section>

            <!-- ============================== report (F-CHR-004) ======= -->
            <section class="card" aria-labelledby="report-h">
                <h2 id="report-h">{{ t('c_legislature_pages.committee_detail.report_h2', 'Committee report') }}</h2>
                <FormCard
                    v-if="can.fileReport && formMeta('F-CHR-004')"
                    :form="formMeta('F-CHR-004')"
                    :inertia-form="reportForm"
                    :submit-label="t('c_legislature_pages.committee_detail.file_report', 'File report')"
                    @submit="submitReport"
                >
                    <Field :label="t('c_legislature_pages.committee_detail.field_title', 'Title')" :error="reportForm.errors.title" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="reportForm.title"
                                class="field-input"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                    <Field :label="t('c_legislature_pages.committee_detail.report_body', 'Report body')" :error="reportForm.errors.body ?? reportForm.errors.constitution" required>
                        <template #control="{ id, invalid, describedBy }">
                            <textarea
                                :id="id"
                                v-model="reportForm.body"
                                class="field-input"
                                rows="4"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                    <Field :label="t('c_legislature_pages.committee_detail.about_bill', 'About bill (optional)')">
                        <template #control="{ id }">
                            <select :id="id" v-model="reportForm.bill_id" class="select">
                                <option value="">{{ t('c_legislature_pages.committee_detail.bill_none', '— none —') }}</option>
                                <option v-for="bill in reportBillOptions" :key="bill.id" :value="bill.id">{{ bill.title }}</option>
                            </select>
                        </template>
                    </Field>
                    <p class="gloss">{{ t('c_legislature_pages.committee_detail.report_choose', 'Choose a bill from the current bill page, or page through the bills above. Your chosen bill stays selected while you browse.') }}</p>
                </FormCard>
                <p v-else class="citation">
                    {{ t('c_legislature_pages.committee_detail.reports_filed_by', 'Reports are filed by the chair — or the alternate when the chair is absent (F-CHR-004 · R-12/R-13). The report body publishes to the public record.') }}
                </p>
            </section>
        </div>

        <template #about>
            <p>
                {{ t('c_legislature_pages.committee_detail.about_1', 'The committee decides by majority of all its members; a passed vote flips the bill to') }} <em>{{ t('c_legislature_pages.committee_detail.about_reported', 'reported') }}</em>{{ t('c_legislature_pages.committee_detail.about_2', ', which is the only state from which the chair\'s F-CHR-003 referral can move it to the floor. The engine enforces the gate server-side — the disabled button is honesty, not the boundary.') }}
            </p>
        </template>
    </PageScaffold>
</template>
