<script setup>
/**
 * Legislature/SessionConsole — FE-C3 (PHASE_C_DESIGN_frontend.md §B.2;
 * surface legislature/session-console).
 *
 * Call & open (F-SPK-001) · attendance + the PEG quorum meter(s)
 * (F-LEG-002 / F-SPK-003 — two meters per kind in bicameral chambers,
 * q-ledger #q7) · the locked AgendaStrip (F-SPK-002) · motions with
 * VoteTally + VoteCastList (F-LEG-007 / F-LEG-004 / F-SPK-004) ·
 * statements (F-LEG-006) · adjourn & minutes (F-SPK-009 — re-arms CLK-02,
 * the receipt renders in the flash).
 *
 * Route-gated server-side to chamber members + R-29; every POST is one
 * engine filing — 422s surface verbatim as errors.constitution.
 */
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';
import AgendaStrip from '@/Components/Legislature/AgendaStrip.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import RankList from '@/Components/Electoral/RankList.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    legislature: { type: Object, required: true },
    session: { type: Object, default: null },
    dueBanner: { type: Object, default: null },
    motions: { type: Array, default: () => [] },
    speakerBallot: { type: Object, default: null },
    myAttendanceMarked: { type: Boolean, default: false },
    can: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const live = computed(
    () => props.session !== null && ['scheduled', 'open', 'failed_quorum'].includes(props.session.status),
);
const bicameral = computed(() => props.legislature.mode === 'bicameral');
const noSpeaker = computed(() => props.can.launchSpeakerBallot);

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

/* ------------------------------------------------------------- call ---- */
const callForm = useForm({ open_now: true });
function callSession() {
    callForm.post(`/legislatures/${props.legislature.id}/sessions`, { preserveScroll: true });
}

/* ------------------------------------------------- speaker balloting --- */
const launching = ref(false);
function launchBallot() {
    launching.value = true;
    router.post(`/legislatures/${props.legislature.id}/speaker-ballot`, {}, {
        preserveScroll: true,
        onFinish: () => {
            launching.value = false;
        },
    });
}

const speakerRanking = ref(
    (props.speakerBallot?.candidates ?? []).map((c) => ({ id: c.id, name: c.name, chips: [] })),
);
const castingBallot = ref(false);
function castSpeakerRanking() {
    const ballot = props.speakerBallot?.vote;
    if (!ballot) return;
    castingBallot.value = true;
    router.post(`/votes/${ballot.vote_id}/cast`, {
        rankings: speakerRanking.value.map((c) => c.id),
    }, {
        preserveScroll: true,
        onFinish: () => {
            castingBallot.value = false;
        },
    });
}

/* ------------------------------------------------------- attendance ---- */
const marking = ref(false);
function markPresent() {
    marking.value = true;
    router.post(`/sessions/${props.session.id}/attendance`, {}, {
        preserveScroll: true,
        onFinish: () => {
            marking.value = false;
        },
    });
}

const publishing = ref(false);
function publishQuorum() {
    publishing.value = true;
    router.post(`/sessions/${props.session.id}/quorum`, {}, {
        preserveScroll: true,
        onFinish: () => {
            publishing.value = false;
        },
    });
}

const compelling = ref(false);
function compel() {
    compelling.value = true;
    router.post(`/sessions/${props.session.id}/compel`, {}, {
        preserveScroll: true,
        onFinish: () => {
            compelling.value = false;
        },
    });
}

/* Per-kind live presence (display only — the engine recounts at F-SPK-003). */
const presentCount = computed(() => props.session?.present ?? 0);
function kindPresent(kind) {
    const seatKind = kind === 'type_a' ? 'type_a' : 'type_b';
    return (props.session?.attendance ?? []).filter(
        (row) => row.seat_kind === seatKind && ['present', 'compelled'].includes(row.status),
    ).length;
}

const ATTENDANCE_BADGES = {
    present: { tone: 'success', icon: 'check', text: 'Present' },
    absent: { tone: 'warning', icon: 'alert-triangle', text: 'Absent' },
    compelled: { tone: 'info', icon: 'shield', text: 'Compelled' },
    excused: { tone: 'neutral', icon: null, text: 'Excused' },
};

/* ------------------------------------------------------------ agenda --- */
function reorderAgenda(from, to) {
    const unlocked = props.session.agenda.filter((item) => !item.locked);
    const lockedCount = props.session.agenda.length - unlocked.length;
    const a = from - lockedCount;
    const b = to - lockedCount;
    if (a < 0 || b < 0) return;
    const next = [...unlocked];
    const [moved] = next.splice(a, 1);
    next.splice(b, 0, moved);
    router.post(`/sessions/${props.session.id}/agenda`, {
        items: next.map((item) => ({
            kind: item.raw_kind ?? 'general',
            title: item.title,
            ref_type: item.subject?.type ?? null,
            ref_id: item.ref_id,
            status: item.status,
        })),
    }, { preserveScroll: true });
}

/* ------------------------------------------------------------ motion --- */
const motionForm = useForm({ kind: 'procedural', text: '' });
function submitMotion() {
    motionForm.post(`/sessions/${props.session.id}/motions`, {
        preserveScroll: true,
        onSuccess: () => motionForm.reset('text'),
    });
}

const castingVote = ref(null);
function castOnVote(voteId, payload) {
    castingVote.value = voteId;
    router.post(`/votes/${voteId}/cast`, payload, {
        preserveScroll: true,
        onFinish: () => {
            castingVote.value = null;
        },
    });
}

const tiebreakValue = ref('yes');
const breaking = ref(null);
function breakTie(voteId) {
    breaking.value = voteId;
    router.post(`/votes/${voteId}/tiebreak`, { value: tiebreakValue.value }, {
        preserveScroll: true,
        onFinish: () => {
            breaking.value = null;
        },
    });
}

/* --------------------------------------------------------- statement --- */
const statementForm = useForm({ body: '' });
function submitStatement() {
    statementForm.post(`/sessions/${props.session.id}/statements`, {
        preserveScroll: true,
        onSuccess: () => statementForm.reset(),
    });
}

/* ----------------------------------------------------------- adjourn --- */
const adjournForm = useForm({ minutes_body: '' });
function adjourn() {
    adjournForm.post(`/sessions/${props.session.id}/adjourn`, { preserveScroll: true });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="`Session console — ${legislature.name}`">
        <template #intro>
            Call → attendance → the published quorum count → the constitutional agenda →
            motions and votes → statements → adjournment with sealed minutes. The quorum
            denominator is every serving member, never those present — and adjourning
            re-arms the 90-day meeting clock (CLK-02).
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ============================================ CLK-02 due ====== -->
        <Banner v-if="dueBanner" :tone="dueBanner.days_left <= 14 ? 'warning' : 'info'" role="status">
            Session due in {{ dueBanner.days_left }} day{{ dueBanner.days_left === 1 ? '' : 's' }}
            (by {{ dueBanner.due_at }}) — discretion can never produce "no session";
            the scheduler compels it. <span class="citation">CLK-02 · WF-SYS-02 · Art. II §2</span>
        </Banner>

        <!-- ================================== speaker balloting ========= -->
        <Card v-if="noSpeaker" as="section" title="Speaker election — the first order of the first session">
            <p class="cc-small">
                Until the chamber elects its Speaker there is no R-10: sessions cannot be
                humanly called and general business cannot open. The balloting is a
                supermajority ranked-choice vote of ALL serving members — non-casters stay
                in the denominator. <span class="citation">F-LEG-008 · Art. II §3 · WF-LEG-02</span>
            </p>

            <template v-if="speakerBallot?.vote && speakerBallot.vote.status === 'open'">
                <p class="citation" style="margin-block: var(--space-2)">
                    balloting open — {{ speakerBallot.cast_count }} of {{ legislature.serving }} serving have cast ·
                    closes at full participation
                </p>
                <VoteTally
                    mode="unicameral"
                    threshold-class="rcv"
                    :serving="speakerBallot.vote.serving"
                    :required-yes="speakerBallot.vote.requiredYes"
                    :tallies="speakerBallot.vote.tallies"
                    :quorum="speakerBallot.vote.quorum"
                    :outcome="speakerBallot.vote.outcome"
                />
                <h3 style="margin-block-start: var(--space-3)">Your ranking</h3>
                <RankList v-model="speakerRanking" :seats="speakerRanking.length" :removable="false" />
                <div class="cluster">
                    <Btn variant="primary" :disabled="castingBallot" @click="castSpeakerRanking">
                        File my ranking (F-LEG-008)
                    </Btn>
                    <span class="citation">public rankings — chamber votes are the opposite of ballots · Art. II §2</span>
                </div>
            </template>

            <template v-else>
                <div class="cluster" style="margin-block-start: var(--space-2)">
                    <Btn variant="primary" :disabled="launching" @click="launchBallot">Open the speaker balloting</Btn>
                    <FormChip form-id="F-LEG-008" name="Speaker nomination/election vote" />
                </div>
                <template v-if="speakerBallot?.vote && speakerBallot.vote.status === 'closed'">
                    <p class="citation" style="margin-block-start: var(--space-3)">
                        last balloting closed {{ speakerBallot.vote.outcome }} —
                        a failed balloting never auto-loops; open a new ballot (WF-LEG-02)
                    </p>
                </template>
            </template>

            <template v-if="speakerBallot?.rounds?.rounds?.length">
                <h3 style="margin-block-start: var(--space-3)">Round record (protected counting engine)</h3>
                <div v-for="round in speakerBallot.rounds.rounds" :key="round.round" class="card card--inset" style="margin-block-end: var(--space-2)">
                    <span class="eyebrow">round {{ round.round }} — {{ round.action }}{{ round.subject ? ` · ${round.subject}` : '' }}</span>
                    <p class="cc-small mono" data-no-i18n style="margin-block: var(--space-1) 0">
                        <template v-for="tally in round.tallies" :key="tally.member_id">
                            {{ tally.name }}: {{ tally.votes }}&ensp;
                        </template>
                    </p>
                </div>
                <p v-if="speakerBallot.rounds.winner" class="citation">
                    winner: {{ speakerBallot.rounds.winner }} — seated as Speaker · public record kind certification
                </p>
            </template>
        </Card>

        <!-- ======================================== call & open ========= -->
        <Card v-if="!live" as="section">
            <template #title><h2>Call &amp; open a session</h2></template>
            <p v-if="!session" class="cc-small">
                No session has ever been held — the first session constitutes the legislature
                (WF-LEG-01); see the <a :href="`/legislatures/${legislature.id}/chamber`">Chamber checklist</a>.
            </p>
            <FormCard
                v-if="can.isSpeaker && formMeta('F-SPK-001')"
                :form="formMeta('F-SPK-001')"
                :inertia-form="callForm"
                submit-label="Call & open session"
                processing-label="Calling…"
                @submit="callSession"
            >
                <p class="field-hint">
                    Opens immediately: serving + quorum snapshot through the protected
                    functions; attendance rows materialize absent until members register.
                </p>
            </FormCard>
            <p v-else-if="!noSpeaker" class="gloss">
                Sessions are called by the chamber's Speaker (or the system under CLK-02).
            </p>
        </Card>

        <!-- ===================================== the live session ======= -->
        <template v-if="session && live">
            <Card as="section">
                <template #title>
                    <h2>
                        Session {{ session.session_no }}
                        <StatusBadge
                            :tone="session.status === 'open' ? 'success' : session.status === 'failed_quorum' ? 'danger' : 'info'"
                        >{{ session.status.replaceAll('_', ' ') }}</StatusBadge>
                    </h2>
                </template>
                <p class="citation">
                    opened {{ fmt(session.opened_at) }} · serving at open {{ session.serving_at_open }} ·
                    stored as UTC
                </p>
            </Card>

            <!-- ================================ attendance & quorum ===== -->
            <Card as="section" title="Attendance & the quorum call">
                <div class="cluster" style="margin-block-end: var(--space-3)">
                    <Btn
                        v-if="can.attendance && !myAttendanceMarked"
                        variant="primary"
                        size="sm"
                        :disabled="marking"
                        @click="markPresent"
                    >I am present (F-LEG-002)</Btn>
                    <StatusBadge v-else-if="myAttendanceMarked" tone="success" icon="check">Your attendance is registered</StatusBadge>
                    <span class="citation">attendance feeds the quorum call and the public record — never a vote denominator</span>
                </div>

                <div class="stack" style="gap: var(--space-1); margin-block-end: var(--space-3)">
                    <div v-for="row in session.attendance" :key="row.member_id" class="roster-row">
                        <span>
                            <span class="mono">{{ row.seat_no }}</span> ·
                            <strong style="color: var(--gov-fg)">{{ row.name }}</strong>
                            <span v-if="bicameral" class="cc-small"> · {{ row.seat_kind === 'type_b' ? 'type B' : 'type A' }}</span>
                        </span>
                        <StatusBadge
                            :tone="ATTENDANCE_BADGES[row.status]?.tone ?? 'neutral'"
                            :icon="ATTENDANCE_BADGES[row.status]?.icon ?? undefined"
                        >{{ ATTENDANCE_BADGES[row.status]?.text ?? row.status }}</StatusBadge>
                    </div>
                </div>

                <!-- The quorum meter(s): unicameral = one; bicameral = one
                     PER KIND — each kind meets its own peg quorum (q7). -->
                <template v-if="!bicameral">
                    <ThresholdMeter
                        :value="presentCount"
                        :max="session.serving_at_open"
                        :threshold="session.quorum_required"
                        label="Quorum — present of all serving"
                    >
                        {{ presentCount }} of {{ session.serving_at_open }} serving present
                        <template #note>
                            peg quorum {{ session.quorum_required }} of {{ session.serving_at_open }} —
                            majorities compute against all serving members, never those present · Art. II §2
                        </template>
                    </ThresholdMeter>
                </template>
                <div v-else class="grid-2">
                    <div
                        v-for="(req, kind) in session.quorum_required_by_kind"
                        :key="kind"
                        class="card card--inset tally-kind"
                        :class="{ 'tally-kind--type-b': kind === 'type_b' }"
                    >
                        <span class="eyebrow">{{ kind === 'type_a' ? 'Type A · population-apportioned' : 'Type B · one per constituent' }}</span>
                        <ThresholdMeter
                            :value="kindPresent(kind)"
                            :max="session.serving_by_kind?.[kind] ?? 0"
                            :threshold="req"
                            :label="`Quorum of this kind — ${kind}`"
                        >
                            {{ kindPresent(kind) }} of {{ session.serving_by_kind?.[kind] ?? 0 }} serving present
                            <template #note>
                                peg quorum of this kind: {{ req }} of {{ session.serving_by_kind?.[kind] ?? 0 }} serving ·
                                Art. V §3 · ledger #q7
                            </template>
                        </ThresholdMeter>
                    </div>
                </div>

                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn
                        v-if="can.publishQuorum"
                        variant="primary"
                        size="sm"
                        :disabled="publishing"
                        @click="publishQuorum"
                    >Publish the quorum count (F-SPK-003)</Btn>
                    <StatusBadge v-if="session.quorum_met === true" tone="success" icon="check">Quorum met — published</StatusBadge>
                    <StatusBadge v-else-if="session.quorum_met === false" tone="danger" icon="alert-triangle">Quorum NOT met</StatusBadge>
                </div>

                <!-- WF-LEG-20 failure branch. -->
                <template v-if="session.status === 'failed_quorum'">
                    <Banner tone="warning" title="Quorum failed — WF-LEG-20." style="margin-block-start: var(--space-3)">
                        Compel attendance, then re-publish the count when members arrive; or
                        adjourn &amp; reschedule inside the CLK-02 window. Repeated failure
                        refers to the administrative office.
                        <span class="citation">Art. II §2 · the 90-day clock is still enforced — a failed-quorum session never resets it</span>
                    </Banner>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <Btn v-if="can.compel" variant="secondary" size="sm" :disabled="compelling" @click="compel">
                            Issue compulsion order (F-SPK-008)
                        </Btn>
                    </div>
                </template>
            </Card>

            <!-- ============================================ agenda ====== -->
            <Card as="section" title="Agenda — constitutional order">
                <p class="citation">
                    slots 1–2 locked: outstanding emergency powers first, constitutional matters
                    second — cannot be reordered or removed · Art. II §2; §7 · hardened
                </p>
                <AgendaStrip
                    :items="session.agenda"
                    :editable="can.setAgenda && session.status === 'open'"
                    @reorder="reorderAgenda"
                />
            </Card>

            <!-- =========================================== motions ====== -->
            <Card as="section" title="Motions">
                <div v-if="motions.length" class="stack" style="gap: var(--space-4); margin-block-end: var(--space-4)">
                    <div v-for="motion in motions" :key="motion.id" class="card card--inset">
                        <p style="margin-block-end: var(--space-1)">
                            <strong style="color: var(--gov-fg)">{{ motion.text }}</strong>
                            <StatusBadge
                                :tone="motion.status === 'adopted' ? 'success' : motion.status === 'failed' ? 'danger' : 'info'"
                                style="margin-inline-start: var(--space-2)"
                            >{{ motion.status }}</StatusBadge>
                        </p>
                        <p class="citation">
                            {{ motion.kind.replaceAll('_', ' ') }} · moved by {{ motion.moved_by }}
                            <template v-if="motion.bill_id"> · <a :href="`/bills/${motion.bill_id}`">bill →</a></template>
                        </p>

                        <template v-if="motion.vote">
                            <VoteTally
                                :mode="motion.vote.mode"
                                :stage="motion.vote.stage"
                                :threshold-class="motion.vote.thresholdClass"
                                :serving="motion.vote.serving"
                                :required-yes="motion.vote.requiredYes"
                                :tallies="motion.vote.tallies"
                                :quorum="motion.vote.quorum"
                                :kinds="motion.vote.kinds"
                                :outcome="motion.vote.outcome"
                                :speaker-tiebreak="motion.vote.speakerTiebreak"
                                :can-cast="can.vote && motion.vote.outcome === 'pending' && !can.isSpeaker"
                                :casting="castingVote === motion.vote.vote_id"
                                @cast="(payload) => castOnVote(motion.vote.vote_id, payload)"
                            />
                            <!-- F-SPK-004 — the only Speaker vote, tie-state only. -->
                            <div
                                v-if="motion.vote.outcome === 'tied' && can.isSpeaker"
                                class="cluster"
                                style="margin-block-start: var(--space-2)"
                            >
                                <label class="field-label" for="tiebreak-value" style="margin-block-end: 0">Tie-breaking vote</label>
                                <select id="tiebreak-value" v-model="tiebreakValue" class="select" style="inline-size: auto">
                                    <option value="yes">yes</option>
                                    <option value="no">no</option>
                                </select>
                                <Btn variant="primary" size="sm" :disabled="breaking === motion.vote.vote_id" @click="breakTie(motion.vote.vote_id)">
                                    Break the tie (F-SPK-004)
                                </Btn>
                            </div>
                            <details v-if="motion.casts?.length" style="margin-block-start: var(--space-2)">
                                <summary class="citation" style="cursor: pointer">Published casts ({{ motion.casts.length }})</summary>
                                <VoteCastList :casts="motion.casts" :group-by-kind="bicameral" />
                            </details>
                        </template>
                    </div>
                </div>
                <p v-else class="gloss">No motions this session.</p>

                <FormCard
                    v-if="can.submitMotion && formMeta('F-LEG-007')"
                    :form="formMeta('F-LEG-007')"
                    :inertia-form="motionForm"
                    submit-label="Submit motion"
                    processing-label="Submitting…"
                    @submit="submitMotion"
                >
                    <Field label="Kind" :error="motionForm.errors.kind">
                        <template #control="{ id }">
                            <select :id="id" v-model="motionForm.kind" class="select">
                                <option value="procedural">procedural</option>
                                <option value="adjourn">adjourn</option>
                                <option value="replace_speaker">replace speaker</option>
                                <option value="other">other</option>
                            </select>
                        </template>
                    </Field>
                    <Field
                        label="Motion text"
                        :error="motionForm.errors.text ?? motionForm.errors.constitution"
                        hint="The deciding vote opens in the same filing — ordinary majority of all serving."
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <textarea
                                :id="id"
                                v-model="motionForm.text"
                                class="field-input"
                                rows="2"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                </FormCard>
                <p v-else-if="can.isSpeaker" class="gloss">
                    The Speaker presides and does not move or vote on business — only the
                    tie-breaking vote (F-SPK-004) · Art. II §3.
                </p>
            </Card>

            <!-- =========================== statements + adjournment ===== -->
            <div class="grid-2">
                <Card as="section" title="Statements — into the public record">
                    <FormCard
                        v-if="can.statement && formMeta('F-LEG-006')"
                        :form="formMeta('F-LEG-006')"
                        :inertia-form="statementForm"
                        submit-label="Publish statement"
                        processing-label="Publishing…"
                        @submit="submitStatement"
                    >
                        <Field
                            label="Statement"
                            hint="Entered verbatim into the immutable public record · WF-SYS-03."
                            :error="statementForm.errors.body ?? statementForm.errors.constitution"
                        >
                            <template #control="{ id, invalid, describedBy }">
                                <textarea
                                    :id="id"
                                    v-model="statementForm.body"
                                    class="field-input"
                                    rows="3"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                ></textarea>
                            </template>
                        </Field>
                    </FormCard>
                </Card>

                <Card as="section" title="Adjourn & seal the minutes">
                    <FormCard
                        v-if="can.adjourn && formMeta('F-SPK-009')"
                        :form="formMeta('F-SPK-009')"
                        :inertia-form="adjournForm"
                        submit-label="Adjourn & publish minutes"
                        processing-label="Adjourning…"
                        @submit="adjourn"
                    >
                        <Field
                            label="Minutes"
                            hint="Sealed to the public record; adjourning a quorum-met session re-arms CLK-02 from the meeting — the confirmation shows the re-armed deadline."
                            :error="adjournForm.errors.minutes_body ?? adjournForm.errors.constitution"
                        >
                            <template #control="{ id, invalid, describedBy }">
                                <textarea
                                    :id="id"
                                    v-model="adjournForm.minutes_body"
                                    class="field-input"
                                    rows="4"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                ></textarea>
                            </template>
                        </Field>
                    </FormCard>
                    <p v-else class="gloss">Adjournment is the Speaker's (or admin staff's) filing — F-SPK-009.</p>
                </Card>
            </div>
        </template>

        <!-- ============================== last adjourned session ======== -->
        <Card v-else-if="session && !live" as="section">
            <template #title>
                <h2>
                    Session {{ session.session_no }} — record
                    <StatusBadge :tone="session.status === 'adjourned' ? 'neutral' : 'danger'">{{ session.status.replaceAll('_', ' ') }}</StatusBadge>
                </h2>
            </template>
            <p class="cc-small">
                Opened {{ fmt(session.opened_at) }} · adjourned {{ fmt(session.adjourned_at) }} ·
                quorum {{ session.quorum_met ? 'met' : 'not met' }}
                ({{ session.present }} of {{ session.serving_at_open }} serving present;
                required {{ session.quorum_required }}).
            </p>
            <p v-if="session.minutes_record_href" class="citation">
                minutes sealed · <a :href="session.minutes_record_href">audit-chain entry →</a>
            </p>
            <DataTable
                :columns="[
                    { key: 'name', label: 'Member' },
                    { key: 'status', label: 'Attendance' },
                ]"
                :rows="session.attendance"
                row-key="member_id"
                caption="Attendance record"
            >
                <template #cell-status="{ row }">
                    <StatusBadge
                        :tone="ATTENDANCE_BADGES[row.status]?.tone ?? 'neutral'"
                    >{{ ATTENDANCE_BADGES[row.status]?.text ?? row.status }}</StatusBadge>
                </template>
            </DataTable>
        </Card>

        <template #about>
            <p>
                The session machine: scheduled → open → adjourned, with the failed-quorum
                branch (WF-LEG-20). Quorum counts precede everything; it is the call itself,
                not an agenda item.
            </p>
        </template>
    </PageScaffold>
</template>
