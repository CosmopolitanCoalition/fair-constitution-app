<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
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
 * Public gallery with role-scoped controls; every POST is one engine
 * filing — 422s surface verbatim as errors.constitution.
 */
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LegislatureWorkspaceNav from '@/Components/Legislature/LegislatureWorkspaceNav.vue';
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
    workspace: { type: Object, required: true },
    legislature: { type: Object, required: true },
    session: { type: Object, default: null },
    dueBanner: { type: Object, default: null },
    motions: { type: Array, default: () => [] },
    speakerBallot: { type: Object, default: null },
    myAttendanceMarked: { type: Boolean, default: false },
    can: { type: Object, required: true },
});

const page = usePage();
const { t } = useI18n();
const text = key => t('c_legislature_workspace.' + key);
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const live = computed(
    () => props.session !== null && ['scheduled', 'open', 'failed_quorum'].includes(props.session.status),
);
const bicameral = computed(() => props.legislature.mode === 'bicameral');
const noSpeaker = computed(() => !props.workspace.hasSpeaker);

function fmt(iso) {
    return iso ? localeFmt.dateTime(new Date(iso)) : '—';
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

const attendanceBadges = computed(() => ({
    present: { tone: 'success', icon: 'check', text: t('c_legislature_workspace.session_console.badge_present', 'Present') },
    absent: { tone: 'warning', icon: 'alert-triangle', text: t('c_legislature_workspace.session_console.badge_absent', 'Absent') },
    compelled: { tone: 'info', icon: 'shield', text: t('c_legislature_workspace.session_console.badge_compelled', 'Compelled') },
    excused: { tone: 'neutral', icon: null, text: t('c_legislature_workspace.session_console.badge_excused', 'Excused') },
}));

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
    <PageScaffold :surface="surface" :title="t('c_legislature_workspace.session_console.title', { name: legislature.name }, 'Session console — {name}')">
        <LegislatureWorkspaceNav :workspace="workspace" active="session" />
        <nav class="cluster" :aria-label="t('c_legislature_workspace.session_console.history_aria', 'Session history')">
            <Link :href="`/legislatures/${legislature.id}/sessions`">{{ t('c_legislature_workspace.session_console.all_sessions', 'All sessions') }}</Link>
            <Link v-if="session" :href="`/legislatures/${legislature.id}/session?session=${session.id}`">{{ t('c_legislature_workspace.session_console.browse_full', 'Browse this session’s full record') }}</Link>
        </nav>
        <nav v-if="session && live" class="cluster" :aria-label="text('session_sections')">
            <a href="#session-attendance">{{ text('attendance') }}</a>
            <a href="#session-agenda">{{ text('agenda') }}</a>
            <a href="#session-motions">{{ text('motions') }}</a>
            <a href="#session-minutes">{{ text('minutes') }}</a>
        </nav>

        <!-- §10-1 gallery: a session is a civic proceeding, public to watch. -->
        <Banner v-if="can.isGallery" tone="info" role="status">
            {{ text('gallery') }}
        </Banner>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ============================================ CLK-02 due ====== -->
        <Banner v-if="dueBanner" :tone="dueBanner.days_left <= 14 ? 'warning' : 'info'" role="status">
            {{ t('c_legislature_workspace.session_console.due_banner', { days: dueBanner.days_left, unit: dueBanner.days_left === 1 ? t('c_legislature_workspace.session_console.day', 'day') : t('c_legislature_workspace.session_console.days', 'days'), due: dueBanner.due_at }, 'Session due in {days} {unit} (by {due}) — discretion can never produce "no session"; the scheduler compels it.') }}
            <span class="citation" data-no-i18n>CLK-02 · WF-SYS-02 · Art. II §2</span>
        </Banner>

        <!-- ================================== speaker balloting ========= -->
        <Card v-if="noSpeaker" as="section" :title="text('speaker_election')">
            <p class="cc-small">{{ text('speaker_election_waiting') }}</p>

            <template v-if="speakerBallot?.vote && speakerBallot.vote.status === 'open'">
                <p class="citation" style="margin-block: var(--space-2)">
                    {{ t('c_legislature_workspace.session_console.balloting_open', { cast: speakerBallot.cast_count, serving: legislature.serving }, 'balloting open — {cast} of {serving} serving have cast · closes at full participation') }}
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
                <h3 v-if="can.vote" style="margin-block-start: var(--space-3)">{{ t('c_legislature_workspace.session_console.your_ranking', 'Your ranking') }}</h3>
                <RankList v-if="can.vote" v-model="speakerRanking" :seats="speakerRanking.length" :removable="false" />
                <div v-if="can.vote" class="cluster">
                    <Btn variant="primary" :disabled="castingBallot" @click="castSpeakerRanking">
                        {{ t('c_legislature_workspace.session_console.file_ranking', 'File my ranking (F-LEG-008)') }}
                    </Btn>
                    <span class="citation">{{ t('c_legislature_workspace.session_console.public_rankings', 'public rankings — chamber votes are the opposite of ballots · Art. II §2') }}</span>
                </div>
            </template>

            <template v-else>
                <div v-if="can.launchSpeakerBallot" class="cluster" style="margin-block-start: var(--space-2)">
                    <Btn variant="primary" :disabled="launching" @click="launchBallot">{{ t('c_legislature_workspace.session_console.open_balloting', 'Open the speaker balloting') }}</Btn>
                    <FormChip form-id="F-LEG-008" :name="t('c_legislature_workspace.session_console.speaker_vote_name', 'Speaker nomination/election vote')" />
                </div>
                <template v-if="speakerBallot?.vote && speakerBallot.vote.status === 'closed'">
                    <p class="citation" style="margin-block-start: var(--space-3)">
                        {{ t('c_legislature_workspace.session_console.last_balloting', { outcome: speakerBallot.vote.outcome }, 'last balloting closed {outcome} — a failed balloting never auto-loops; open a new ballot (WF-LEG-02)') }}
                    </p>
                </template>
            </template>

            <template v-if="speakerBallot?.rounds?.rounds?.length">
                <h3 style="margin-block-start: var(--space-3)">{{ t('c_legislature_workspace.session_console.round_record', 'Round record (protected counting engine)') }}</h3>
                <div v-for="round in speakerBallot.rounds.rounds" :key="round.round" class="card card--inset" style="margin-block-end: var(--space-2)">
                    <span class="eyebrow">{{ t('c_legislature_workspace.session_console.round_label', { n: round.round }, 'round {n}') }} — {{ round.action }}{{ round.subject ? ` · ${round.subject}` : '' }}</span>
                    <p class="cc-small mono" data-no-i18n style="margin-block: var(--space-1) 0">
                        <template v-for="tally in round.tallies" :key="tally.member_id">
                            {{ tally.name }}: {{ tally.votes }}&ensp;
                        </template>
                    </p>
                </div>
                <p v-if="speakerBallot.rounds.winner" class="citation">
                    {{ t('c_legislature_workspace.session_console.winner', { name: speakerBallot.rounds.winner }, 'winner: {name} — seated as Speaker · public record kind certification') }}
                </p>
            </template>
        </Card>

        <!-- ======================================== call & open ========= -->
        <Card v-if="!live" as="section">
            <template #title><h2>{{ t('c_legislature_workspace.session_console.call_open_title', 'Call & open a session') }}</h2></template>
            <p v-if="!session" class="cc-small">
                {{ t('c_legislature_workspace.session_console.no_session_ever', 'No session has ever been held — the first session constitutes the legislature (WF-LEG-01); see the') }}
                <a :href="`/legislatures/${legislature.id}/chamber`">{{ t('c_legislature_workspace.session_console.chamber_checklist', 'Chamber checklist') }}</a>.
            </p>
            <FormCard
                v-if="can.isSpeaker && formMeta('F-SPK-001')"
                :form="formMeta('F-SPK-001')"
                :inertia-form="callForm"
                :submit-label="t('c_legislature_workspace.session_console.call_open_submit', 'Call & open session')"
                :processing-label="t('c_legislature_workspace.session_console.calling', 'Calling…')"
                @submit="callSession"
            >
                <p class="field-hint">
                    {{ t('c_legislature_workspace.session_console.call_hint', 'Opens immediately: serving + quorum snapshot through the protected functions; attendance rows materialize absent until members register.') }}
                </p>
            </FormCard>
            <p v-else-if="!noSpeaker" class="gloss">
                {{ t('c_legislature_workspace.session_console.called_by_speaker', 'Sessions are called by the chamber\'s Speaker (or the system under CLK-02).') }}
            </p>
        </Card>

        <!-- ===================================== the live session ======= -->
        <template v-if="session && live">
            <Card as="section">
                <template #title>
                    <h2>
                        {{ t('c_legislature_workspace.session_console.session_no', { n: session.session_no }, 'Session {n}') }}
                        <StatusBadge
                            :tone="session.status === 'open' ? 'success' : session.status === 'failed_quorum' ? 'danger' : 'info'"
                        >{{ session.status.replaceAll('_', ' ') }}</StatusBadge>
                    </h2>
                </template>
                <p class="citation">
                    {{ t('c_legislature_workspace.session_console.opened_line', { opened: fmt(session.opened_at), serving: session.serving_at_open }, 'opened {opened} · serving at open {serving} · stored as UTC') }}
                </p>
            </Card>

            <!-- ================================ attendance & quorum ===== -->
            <Card id="session-attendance" as="section" :title="t('c_legislature_workspace.session_console.attendance_title', 'Attendance & the quorum call')">
                <div class="cluster" style="margin-block-end: var(--space-3)">
                    <Btn
                        v-if="can.attendance && !myAttendanceMarked"
                        variant="primary"
                        size="sm"
                        :disabled="marking"
                        @click="markPresent"
                    >{{ t('c_legislature_workspace.session_console.i_am_present', 'I am present (F-LEG-002)') }}</Btn>
                    <StatusBadge v-else-if="myAttendanceMarked" tone="success" icon="check">{{ t('c_legislature_workspace.session_console.attendance_registered', 'Your attendance is registered') }}</StatusBadge>
                    <span class="citation">{{ t('c_legislature_workspace.session_console.attendance_note', 'attendance feeds the quorum call and the public record — never a vote denominator') }}</span>
                </div>

                <div class="stack" style="gap: var(--space-1); margin-block-end: var(--space-3)">
                    <div v-for="row in session.attendance" :key="row.member_id" class="roster-row">
                        <span>
                            <span class="mono">{{ row.seat_no }}</span> ·
                            <strong style="color: var(--gov-fg)">{{ row.name }}</strong>
                            <span v-if="bicameral" class="cc-small"> · {{ row.seat_kind === 'type_b' ? t('c_legislature_workspace.session_console.type_b_chip', 'type B') : t('c_legislature_workspace.session_console.type_a_chip', 'type A') }}</span>
                        </span>
                        <StatusBadge
                            :tone="attendanceBadges[row.status]?.tone ?? 'neutral'"
                            :icon="attendanceBadges[row.status]?.icon ?? undefined"
                        >{{ attendanceBadges[row.status]?.text ?? row.status }}</StatusBadge>
                    </div>
                </div>

                <!-- The quorum meter(s): unicameral = one; bicameral = one
                     PER KIND — each kind meets its own peg quorum (q7). -->
                <template v-if="!bicameral">
                    <ThresholdMeter
                        :value="presentCount"
                        :max="session.serving_at_open"
                        :threshold="session.quorum_required"
                        :label="t('c_legislature_workspace.session_console.quorum_label', 'Quorum — present of all serving')"
                    >
                        {{ t('c_legislature_workspace.session_console.present_of', { present: presentCount, serving: session.serving_at_open }, '{present} of {serving} serving present') }}
                        <template #note>
                            {{ t('c_legislature_workspace.session_console.peg_quorum_note', { req: session.quorum_required, serving: session.serving_at_open }, 'peg quorum {req} of {serving} — majorities compute against all serving members, never those present · Art. II §2') }}
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
                        <span class="eyebrow">{{ kind === 'type_a' ? t('c_legislature_workspace.session_console.kind_type_a', 'Type A · population-apportioned') : t('c_legislature_workspace.session_console.kind_type_b', 'Type B · one per constituent') }}</span>
                        <ThresholdMeter
                            :value="kindPresent(kind)"
                            :max="session.serving_by_kind?.[kind] ?? 0"
                            :threshold="req"
                            :label="t('c_legislature_workspace.session_console.quorum_kind_label', { kind }, 'Quorum of this kind — {kind}')"
                        >
                            {{ t('c_legislature_workspace.session_console.present_of', { present: kindPresent(kind), serving: session.serving_by_kind?.[kind] ?? 0 }, '{present} of {serving} serving present') }}
                            <template #note>
                                {{ t('c_legislature_workspace.session_console.peg_quorum_kind_note', { req, serving: session.serving_by_kind?.[kind] ?? 0 }, 'peg quorum of this kind: {req} of {serving} serving · Art. V §3 · ledger #q7') }}
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
                    >{{ t('c_legislature_workspace.session_console.publish_quorum', 'Publish the quorum count (F-SPK-003)') }}</Btn>
                    <StatusBadge v-if="session.quorum_met === true" tone="success" icon="check">{{ t('c_legislature_workspace.session_console.quorum_met', 'Quorum met — published') }}</StatusBadge>
                    <StatusBadge v-else-if="session.quorum_met === false" tone="danger" icon="alert-triangle">{{ t('c_legislature_workspace.session_console.quorum_not_met', 'Quorum NOT met') }}</StatusBadge>
                </div>

                <!-- WF-LEG-20 failure branch. -->
                <template v-if="session.status === 'failed_quorum'">
                    <Banner tone="warning" :title="t('c_legislature_workspace.session_console.quorum_failed_title', 'Quorum failed — WF-LEG-20.')" style="margin-block-start: var(--space-3)">
                        {{ t('c_legislature_workspace.session_console.quorum_failed_body', 'Compel attendance, then re-publish the count when members arrive; or adjourn & reschedule inside the CLK-02 window. Repeated failure refers to the administrative office.') }}
                        <span class="citation">{{ t('c_legislature_workspace.session_console.quorum_failed_note', 'Art. II §2 · the 90-day clock is still enforced — a failed-quorum session never resets it') }}</span>
                    </Banner>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <Btn v-if="can.compel" variant="secondary" size="sm" :disabled="compelling" @click="compel">
                            {{ t('c_legislature_workspace.session_console.issue_compulsion', 'Issue compulsion order (F-SPK-008)') }}
                        </Btn>
                    </div>
                </template>
            </Card>

            <!-- ============================================ agenda ====== -->
            <Card id="session-agenda" as="section" :title="t('c_legislature_workspace.session_console.agenda_title', 'Agenda — constitutional order')">
                <p class="citation">
                    {{ t('c_legislature_workspace.session_console.agenda_note', 'slots 1–2 locked: outstanding emergency powers first, constitutional matters second — cannot be reordered or removed · Art. II §2; §7 · hardened') }}
                </p>
                <AgendaStrip
                    :items="session.agenda"
                    :editable="can.setAgenda && session.status === 'open'"
                    @reorder="reorderAgenda"
                />
            </Card>

            <!-- =========================================== motions ====== -->
            <Card id="session-motions" as="section" :title="t('c_legislature_workspace.session_console.motions_title', 'Motions')">
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
                            {{ motion.kind.replaceAll('_', ' ') }} · {{ t('c_legislature_workspace.session_console.moved_by', { name: motion.moved_by }, 'moved by {name}') }}
                            <template v-if="motion.bill_id"> · <a :href="`/bills/${motion.bill_id}`">{{ t('c_legislature_workspace.session_console.bill_link', 'bill →') }}</a></template>
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
                                <label class="field-label" for="tiebreak-value" style="margin-block-end: 0">{{ t('c_legislature_workspace.session_console.tiebreak_label', 'Tie-breaking vote') }}</label>
                                <select id="tiebreak-value" v-model="tiebreakValue" class="select" style="inline-size: auto">
                                    <option value="yes">{{ t('c_legislature_workspace.session_console.opt_yes', 'yes') }}</option>
                                    <option value="no">{{ t('c_legislature_workspace.session_console.opt_no', 'no') }}</option>
                                </select>
                                <Btn variant="primary" size="sm" :disabled="breaking === motion.vote.vote_id" @click="breakTie(motion.vote.vote_id)">
                                    {{ t('c_legislature_workspace.session_console.break_tie', 'Break the tie (F-SPK-004)') }}
                                </Btn>
                            </div>
                            <details v-if="motion.casts?.length" style="margin-block-start: var(--space-2)">
                                <summary class="citation" style="cursor: pointer">{{ t('c_legislature_workspace.session_console.published_casts', { n: motion.casts.length }, 'Published casts ({n})') }}</summary>
                                <VoteCastList :casts="motion.casts" :group-by-kind="bicameral" />
                            </details>
                        </template>
                    </div>
                </div>
                <p v-else class="gloss">{{ t('c_legislature_workspace.session_console.no_motions', 'No motions this session.') }}</p>

                <FormCard
                    v-if="can.submitMotion && formMeta('F-LEG-007')"
                    :form="formMeta('F-LEG-007')"
                    :inertia-form="motionForm"
                    :submit-label="t('c_legislature_workspace.session_console.submit_motion', 'Submit motion')"
                    :processing-label="t('c_legislature_workspace.session_console.submitting', 'Submitting…')"
                    @submit="submitMotion"
                >
                    <Field :label="t('c_legislature_workspace.session_console.kind_label', 'Kind')" :error="motionForm.errors.kind">
                        <template #control="{ id }">
                            <select :id="id" v-model="motionForm.kind" class="select">
                                <option value="procedural">{{ t('c_legislature_workspace.session_console.motion_procedural', 'procedural') }}</option>
                                <option value="adjourn">{{ t('c_legislature_workspace.session_console.motion_adjourn', 'adjourn') }}</option>
                                <option value="replace_speaker">{{ t('c_legislature_workspace.session_console.motion_replace_speaker', 'replace speaker') }}</option>
                                <option value="other">{{ t('c_legislature_workspace.session_console.motion_other', 'other') }}</option>
                            </select>
                        </template>
                    </Field>
                    <Field
                        :label="t('c_legislature_workspace.session_console.motion_text_label', 'Motion text')"
                        :error="motionForm.errors.text ?? motionForm.errors.constitution"
                        :hint="t('c_legislature_workspace.session_console.motion_text_hint', 'The deciding vote opens in the same filing — ordinary majority of all serving.')"
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
                    {{ t('c_legislature_workspace.session_console.speaker_presides', 'The Speaker presides and does not move or vote on business — only the tie-breaking vote (F-SPK-004) · Art. II §3.') }}
                </p>
            </Card>

            <!-- =========================== statements + adjournment ===== -->
            <div id="session-minutes" class="grid-2">
                <Card as="section" :title="t('c_legislature_workspace.session_console.statements_title', 'Statements — into the public record')">
                    <FormCard
                        v-if="can.statement && formMeta('F-LEG-006')"
                        :form="formMeta('F-LEG-006')"
                        :inertia-form="statementForm"
                        :submit-label="t('c_legislature_workspace.session_console.publish_statement', 'Publish statement')"
                        :processing-label="t('c_legislature_workspace.session_console.publishing', 'Publishing…')"
                        @submit="submitStatement"
                    >
                        <Field
                            :label="t('c_legislature_workspace.session_console.statement_label', 'Statement')"
                            :hint="t('c_legislature_workspace.session_console.statement_hint', 'Entered verbatim into the immutable public record · WF-SYS-03.')"
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
                    <p v-else class="gloss">{{ text('statement_empty') }}</p>
                </Card>

                <Card as="section" :title="t('c_legislature_workspace.session_console.adjourn_title', 'Adjourn & seal the minutes')">
                    <FormCard
                        v-if="can.adjourn && formMeta('F-SPK-009')"
                        :form="formMeta('F-SPK-009')"
                        :inertia-form="adjournForm"
                        :submit-label="t('c_legislature_workspace.session_console.adjourn_submit', 'Adjourn & publish minutes')"
                        :processing-label="t('c_legislature_workspace.session_console.adjourning', 'Adjourning…')"
                        @submit="adjourn"
                    >
                        <Field
                            :label="t('c_legislature_workspace.session_console.minutes_label', 'Minutes')"
                            :hint="t('c_legislature_workspace.session_console.minutes_hint', 'Sealed to the public record; adjourning a quorum-met session re-arms CLK-02 from the meeting — the confirmation shows the re-armed deadline.')"
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
                    <p v-else class="gloss">{{ t('c_legislature_workspace.session_console.adjourn_gloss', 'Adjournment is the Speaker\'s (or admin staff\'s) filing — F-SPK-009.') }}</p>
                </Card>
            </div>
        </template>

        <!-- ============================== last adjourned session ======== -->
        <Card v-else-if="session && !live" as="section">
            <template #title>
                <h2>
                    {{ t('c_legislature_workspace.session_console.record_no', { n: session.session_no }, 'Session {n} — record') }}
                    <StatusBadge :tone="session.status === 'adjourned' ? 'neutral' : 'danger'">{{ session.status.replaceAll('_', ' ') }}</StatusBadge>
                </h2>
            </template>
            <p class="cc-small">
                {{ t('c_legislature_workspace.session_console.record_summary', { opened: fmt(session.opened_at), adjourned: fmt(session.adjourned_at), quorum: session.quorum_met ? t('c_legislature_workspace.session_console.q_met', 'met') : t('c_legislature_workspace.session_console.q_not_met', 'not met'), present: session.present, serving: session.serving_at_open, required: session.quorum_required }, 'Opened {opened} · adjourned {adjourned} · quorum {quorum} ({present} of {serving} serving present; required {required}).') }}
            </p>
            <p v-if="session.minutes_record_href" class="citation">
                {{ t('c_legislature_workspace.session_console.minutes_sealed', 'minutes sealed ·') }} <a :href="session.minutes_record_href">{{ t('c_legislature_workspace.session_console.audit_chain_entry', 'audit-chain entry →') }}</a>
            </p>
            <DataTable
                :columns="[
                    { key: 'name', label: t('c_legislature_workspace.session_console.col_member', 'Member') },
                    { key: 'status', label: t('c_legislature_workspace.session_console.col_attendance', 'Attendance') },
                ]"
                :rows="session.attendance"
                row-key="member_id"
                :caption="t('c_legislature_workspace.session_console.attendance_record', 'Attendance record')"
            >
                <template #cell-status="{ row }">
                    <StatusBadge
                        :tone="attendanceBadges[row.status]?.tone ?? 'neutral'"
                    >{{ attendanceBadges[row.status]?.text ?? row.status }}</StatusBadge>
                </template>
            </DataTable>
            <h3 style="margin-block-start: var(--space-4)">{{ text('agenda') }}</h3>
            <AgendaStrip :items="session.agenda" :editable="false" />
            <h3 style="margin-block-start: var(--space-4)">{{ text('motions') }}</h3>
            <p v-if="!motions.length" class="gloss">{{ t('c_legislature_workspace.session_console.no_motions', 'No motions this session.') }}</p>
            <details v-for="motion in motions" :key="motion.id" class="session-record-motion">
                <summary>{{ motion.text }} · {{ motion.status }}</summary>
                <p class="citation">{{ motion.moved_by }} <a v-if="motion.bill_id" :href="`/bills/${motion.bill_id}`"> · {{ $t('c_legislature_workspace.bills') }}</a></p>
                <VoteTally
                    v-if="motion.vote"
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
                    :can-cast="false"
                />
                <VoteCastList v-if="motion.casts?.length" :casts="motion.casts" :group-by-kind="bicameral" />
            </details>
        </Card>

        <template #about>
            <p>
                {{ t('c_legislature_workspace.session_console.about', 'The session machine: scheduled → open → adjourned, with the failed-quorum branch (WF-LEG-20). Quorum counts precede everything; it is the call itself, not an agenda item.') }}
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
[id^="session-"] { scroll-margin-block-start: 8rem; }
.session-record-motion { margin-block: var(--space-3); }
.session-record-motion summary { cursor: pointer; padding-block: .6rem; }
</style>
