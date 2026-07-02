<script setup>
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
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import PersonaChip from '@/Components/Ui/PersonaChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    committee: { type: Object, required: true },
    meeting: { type: Object, default: null },
    bills: { type: Array, default: () => [] },
    testimony: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
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
    <PageScaffold :surface="surface" :title="`${committee.name} committee`">
        <template #intro>
            Committee hearings are public record: testimony, member votes with explanations, and
            reports all publish (WF-SYS-03). The committee decides by majority of ALL its members
            — never of those present<template v-if="bicameral">, and in this bicameral chamber each
            seat kind must independently agree at committee stage (Art. V §3 · ledger #q7)</template>.
        </template>

        <p class="cc-small">
            <a :href="committee.legislature.href">← {{ committee.legislature.name }} committees</a>
        </p>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ================================== roster =================== -->
        <Card as="section">
            <template #title>
                <h2>
                    Roster — {{ committee.seats }} seats
                    <template v-if="committee.by_kind">
                        ({{ committee.by_kind.type_a }} type A + {{ committee.by_kind.type_b }} type B)
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
                            <PersonaChip :name="member.name ?? 'Member'" />
                            <TagChip v-if="member.seat_kind">{{ member.seat_kind === 'type_b' ? 'type B' : 'type A' }}</TagChip>
                            <StatusBadge v-if="member.is_chair" tone="warning" icon="landmark">Chair · R-12</StatusBadge>
                            <StatusBadge
                                v-else-if="committee.alternate && member.member_id === committee.alternate.member_id"
                                tone="info"
                            >Alternate · R-13</StatusBadge>
                        </span>
                        <span class="citation" data-no-i18n>{{ member.assigned_via }}</span>
                    </div>
                </div>
            </template>
            <p v-else class="gloss">
                Not yet seated — placements arrive with the F-SPK-005 assignment run on the
                <a :href="committee.legislature.href">committees page</a>.
            </p>
        </Card>

        <!-- ================================== meeting ================== -->
        <Card as="section" title="Meeting">
            <template v-if="meeting">
                <p class="cc-small">
                    {{ meeting.status === 'open' ? 'In session' : 'Scheduled' }} —
                    {{ fmt(meeting.scheduled_for) }} · hearings are public record.
                </p>
                <ol v-if="meeting.agenda.length" class="agenda-list">
                    <li v-for="(item, i) in meeting.agenda" :key="i" class="agenda-slot">
                        <span class="flow-step-n">{{ i + 1 }}</span>
                        <span>{{ item }}</span>
                    </li>
                </ol>
                <p v-else class="gloss">No agenda yet — the chair sets it (F-CHR-002).</p>

                <FormCard
                    v-if="can.setAgenda && formMeta('F-CHR-002')"
                    :form="formMeta('F-CHR-002')"
                    :inertia-form="agendaForm"
                    submit-label="Set agenda"
                    @submit="submitAgenda"
                >
                    <Field
                        label="Agenda items (one per line)"
                        hint="Committee agendas have no engine-locked head — emergency review is a floor-session duty (Art. II §2)."
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
                <p class="gloss">No meeting scheduled.</p>
                <FormCard
                    v-if="can.call && formMeta('F-CHR-001')"
                    :form="formMeta('F-CHR-001')"
                    :inertia-form="meetingForm"
                    submit-label="Call meeting"
                    @submit="submitMeeting"
                >
                    <Field label="Scheduled for" :error="meetingForm.errors.scheduled_for ?? meetingForm.errors.constitution">
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
                    <Field label="Agenda items (one per line)">
                        <template #control="{ id }">
                            <textarea :id="id" v-model="meetingForm.agenda_text" class="field-input" rows="3"></textarea>
                        </template>
                    </Field>
                </FormCard>
                <p v-else-if="!can.call" class="citation">
                    Meetings are called by the chair — or the alternate when the chair is absent (F-CHR-001 · R-12/R-13).
                </p>
            </template>
        </Card>

        <!-- ================================== bills ==================== -->
        <Card as="section" title="Bills before the committee">
            <p v-if="!bills.length" class="gloss">No bills referred to this committee.</p>

            <div class="stack" style="gap: var(--space-3)">
                <Card v-for="bill in bills" :key="bill.id" inset>
                    <div class="cluster" style="justify-content: space-between">
                        <h3 style="font-size: var(--text-base); margin: 0">
                            <a :href="`/bills/${bill.id}`">{{ bill.title }}</a>
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
                            Your cast is recorded — casts are immutable.
                        </p>
                        <details v-if="bill.vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="cc-small" style="cursor: pointer">Published casts ({{ bill.vote.casts.length }})</summary>
                            <VoteCastList :casts="bill.vote.casts" :group-by-kind="bicameral" />
                        </details>
                    </template>
                    <p v-else-if="bill.status === 'in_committee'" class="gloss">
                        The committee vote opens with the referral motion's adoption (WF-LEG-06)
                        — majority of ALL committee members, not those present · Art. II §4.
                    </p>

                    <!-- refer-to-floor gate (F-CHR-003) ------------------ -->
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <Btn
                            v-if="can.refer"
                            variant="primary"
                            size="sm"
                            :disabled="!bill.referable || referring === bill.id"
                            :title="bill.referable
                                ? 'Moves the bill to the floor and opens its floor vote'
                                : 'Enabled only after the committee vote passes · F-CHR-003'"
                            @click="referToFloor(bill)"
                        >Refer to floor (F-CHR-003)</Btn>
                        <span v-if="!bill.referable" class="citation">
                            enabled only after the committee vote passes · F-CHR-003 — the engine
                            independently rejects premature referral
                        </span>
                        <StatusBadge v-if="bill.report" tone="success" icon="check">
                            Report filed {{ fmt(bill.report.filed_at) }}
                        </StatusBadge>
                        <a v-if="bill.report?.record_href" class="citation" :href="bill.report.record_href">sealed record →</a>
                    </div>
                </Card>
            </div>
        </Card>

        <div class="grid-2">
            <!-- ============================== testimony ================ -->
            <section class="card" aria-labelledby="testimony-h">
                <h2 id="testimony-h">Testimony</h2>
                <p class="gloss">
                    Hearings take testimony from any resident; entries publish verbatim to the
                    immutable public record · WF-LEG-08 · WF-SYS-03.
                </p>
                <div v-if="testimony.length" class="stack" style="gap: var(--space-1)">
                    <LogRow v-for="row in testimony" :key="row.seq" :seq="row.seq">
                        <strong>{{ row.who }}</strong> — {{ row.text }}
                        <span class="citation" style="display: block">
                            {{ fmt(row.recorded_at) }} ·
                            <a :href="row.record_href">sealed record →</a>
                        </span>
                    </LogRow>
                </div>
                <p v-else class="cc-small gloss">No testimony recorded yet.</p>

                <template v-if="meeting && can.testify">
                    <Field
                        label="Submit testimony"
                        hint="Entered verbatim into the public record — testimony cannot be edited or withdrawn."
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
                    >Enter into the record</Btn>
                </template>
                <p v-else-if="!meeting" class="citation">Testimony attaches to a meeting — none is scheduled.</p>
            </section>

            <!-- ============================== report (F-CHR-004) ======= -->
            <section class="card" aria-labelledby="report-h">
                <h2 id="report-h">Committee report</h2>
                <FormCard
                    v-if="can.fileReport && formMeta('F-CHR-004')"
                    :form="formMeta('F-CHR-004')"
                    :inertia-form="reportForm"
                    submit-label="File report"
                    @submit="submitReport"
                >
                    <Field label="Title" :error="reportForm.errors.title" required>
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
                    <Field label="Report body" :error="reportForm.errors.body ?? reportForm.errors.constitution" required>
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
                    <Field label="About bill (optional)">
                        <template #control="{ id }">
                            <select :id="id" v-model="reportForm.bill_id" class="select">
                                <option value="">— none —</option>
                                <option v-for="bill in bills" :key="bill.id" :value="bill.id">{{ bill.title }}</option>
                            </select>
                        </template>
                    </Field>
                </FormCard>
                <p v-else class="citation">
                    Reports are filed by the chair — or the alternate when the chair is absent (F-CHR-004 · R-12/R-13).
                    The report body publishes to the public record.
                </p>
            </section>
        </div>

        <template #about>
            <p>
                The committee decides by majority of all its members; a passed vote flips the
                bill to <em>reported</em>, which is the only state from which the chair's
                F-CHR-003 referral can move it to the floor. The engine enforces the gate
                server-side — the disabled button is honesty, not the boundary.
            </p>
        </template>
    </PageScaffold>
</template>
