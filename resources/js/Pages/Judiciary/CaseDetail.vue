<script setup>
/**
 * Judiciary/CaseDetail — FE-E3 (PHASE_E_DESIGN_frontend.md §B.3; surface
 * judiciary/case-detail).
 *
 * The public case record composed through CaseLifecycle: the Case-ESM
 * StateStrip + the 10-stage ordinal track, with per-stage server payloads
 * supplied via the component's #stage-{index} named slots —
 *   3  PanelTable (the conflict-screened bench, en-banc for major questions)
 *   4  motions DataTable        5  evidence DataTable
 *   6  the jury draw Banner + F-JDG-002 order + juror-view cross-link
 *   8  the locked chambers / jury-room cards (the only unrecorded space)
 *   9  the double-jeopardy Banner + F-JDG-009 sentence + F-JDG-010 warrant
 *   10 the F-JDG-003 opinion + challenge-tracker cross-link
 *
 * PUBLIC READ (Art. II §2 — proceedings are public record). Per-stage court
 * actions gate by derived role (R-19/R-20) via `can.orderCourt` + the engine
 * 422 — never a page 403. The court ADVANCES the append-only record through
 * the engine; CaseLifecycle never POSTs (`interactive` — the playable
 * walkthrough cursor — is world-keyed on Demo mode via useDemoMode, and
 * stays OFF on real worlds).
 *
 * `panel.panelSize` / `panel.isFullCourt` are ENGINE SNAPSHOTS (the CLK-16
 * hard constraint), passed straight to PanelTable — never recomputed here.
 */
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import CaseLifecycle from '@/Components/Judiciary/CaseLifecycle.vue';
import PanelTable from '@/Components/Judiciary/PanelTable.vue';
import { useDemoMode } from '@/composables/useDemoMode';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.3 case block — see CaseController::caseProps. */
    case: { type: Object, required: true },
    /** Case ESM (config/cga/state_machines.php 'case'). */
    machine: { type: Array, default: () => [] },
    /** [{ index, title }] — the 10 ordinal lifecycle stages. */
    stages: { type: Array, default: () => [] },
    /** STAGE_STATE: 1-based stage → ESM state (server-authored). */
    stageStateMap: { type: Array, default: () => [] },
    /** PanelTable props (engine snapshots) — null until the case is paneled. */
    panel: { type: Object, default: null },
    /** [{ title, filed_by, ruling, ruling_reason }] — the motions docket. */
    motions: { type: Array, default: () => [] },
    /** [{ title, filed_by, ruling, ruling_reason }] — the evidence docket. */
    evidence: { type: Array, default: () => [] },
    /** { drawn, jurors, alternates, pool_size, pool_label, seed_audit_href } | null. */
    jury: { type: Object, default: null },
    can: { type: Object, default: () => ({ orderCourt: false }) },
});

const page = usePage();
const { t } = useI18n();
/* Demo-mode worlds get the playable Back/Advance cursor (D6, §10 item 4 —
   world-keyed via instance.sandbox, never build-keyed). Display-only either
   way: the cursor previews stages; the record still never POSTs from here. */
const { isDemoMode } = useDemoMode();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const surfaceForm = (id) => props.surface.forms.find((f) => f.id === id) ?? null;

/* `case` is a reserved JS word — template expressions cannot dereference the
   prop directly, so alias it. The PROP stays named `case` (the parent passes
   it, and CaseLifecycle's contract names it `case`). */
const kase = computed(() => props.case);

const isCriminal = computed(() => props.case.kind_raw === 'criminal');
const isFullCourt = computed(() => Boolean(props.panel?.isFullCourt));

/* The court's next action by live status — the form renders where the engine
   will accept it (the same posture the chamber's first-sessions checklist
   takes). The engine re-asserts the legal ESM edge on every POST. */
const state = computed(() => props.case.current_state);
const canAccept = computed(() => props.can.orderCourt && state.value === 'filed');
const canOrderJury = computed(
    () => props.can.orderCourt && state.value === 'paneled' && props.case.jury_entitled && !props.jury,
);
const canSentence = computed(() => props.can.orderCourt && ['decided', 'sentenced'].includes(state.value));
const canWarrant = computed(
    () => props.can.orderCourt && ['accepted', 'paneled', 'jury_empaneled', 'heard', 'decided', 'sentenced'].includes(state.value),
);
const canOpine = computed(() => props.can.orderCourt && ['decided', 'sentenced'].includes(state.value));
const hasCourtAction = computed(
    () => canAccept.value || canOrderJury.value || canSentence.value || canWarrant.value || canOpine.value,
);

/* IO-1 lifecycle controls (operator ruling 2026-09-13). These render whenever
   the viewer is the court (`orderCourt`) and are DISABLED, not hidden, at the
   wrong state — the engine re-asserts the legal ESM edge on every POST. A
   non-court viewer never sees them. */
const isCourt = computed(() => props.can.orderCourt);
const canAdvanceHearing = computed(() => isCourt.value && ['paneled', 'jury_empaneled'].includes(state.value));
const canDeliberate = computed(() => isCourt.value && state.value === 'heard');
const canRecordVerdict = computed(() => isCourt.value && state.value === 'deliberation');
const canDismiss = computed(() => isCourt.value && ['filed', 'accepted'].includes(state.value));
const canRuleFilings = computed(() => isCourt.value && ['accepted', 'paneled', 'jury_empaneled', 'heard'].includes(state.value));

/* The kind/severity header badge tone (mockup case-detail.html header). */
const SEVERITY_TONE = {
    Minor: 'neutral',
    Moderate: 'info',
    Serious: 'warning',
    'Major constitutional question': 'danger',
};
const severityTone = computed(() => {
    const base = (props.case.severity ?? '').replace(' (claimed)', '');
    return SEVERITY_TONE[base] ?? 'neutral';
});

const motionColumns = [
    { key: 'title', label: 'Motion' },
    { key: 'filed_by', label: 'Filed by' },
    { key: 'ruling', label: 'Ruling' },
];
const evidenceColumns = [
    { key: 'title', label: 'Exhibit' },
    { key: 'filed_by', label: 'Submitted by' },
    { key: 'ruling', label: 'Admissibility' },
];
const RULING_TONE = {
    granted: 'success',
    admitted: 'success',
    denied: 'danger',
    excluded: 'danger',
};
const RULING_LABEL = {
    granted: 'Granted',
    denied: 'Denied',
    admitted: 'Admitted',
    excluded: 'Excluded',
};

/* ---------------------------------------------- court action forms ----- */
const acceptForm = useForm({ court_severity: 'serious', jury_waived: false });
function submitAccept() {
    acceptForm.post(`/cases/${props.case.id}/acceptance`, { preserveScroll: true });
}

const juryForm = useForm({ seats: 12, alternates: 2 });
function submitJury() {
    juryForm.post(`/cases/${props.case.id}/jury-orders`, { preserveScroll: true });
}

/* IO-2 — on an appeal case the opinion also records the appellate outcome
   (civil affirm/reverse/remand, criminal affirm/vacate). `appeal_outcomes` is
   the server's lawful list for this case's kind; empty on a first-instance case. */
const isAppeal = computed(() => Boolean(props.case.is_appeal));
const appealOutcomes = computed(() => props.case.appeal_outcomes ?? []);
const APPEAL_OUTCOME_LABEL = {
    affirm: 'Affirm the judgement',
    reverse: 'Reverse the judgement',
    remand: 'Remand for further proceedings',
    vacate: 'Vacate the conviction (the accused is acquitted)',
};
const opinionForm = useForm({
    kind: 'majority',
    title: '',
    body: '',
    appeal_outcome: isAppeal.value ? appealOutcomes.value[0] ?? '' : '',
});
function submitOpinion() {
    opinionForm.post(`/cases/${props.case.id}/opinions`, {
        preserveScroll: true,
        onSuccess: () => opinionForm.reset('title', 'body'),
    });
}

const sentenceForm = useForm({ terms: '' });
function submitSentence() {
    sentenceForm.post(`/cases/${props.case.id}/sentencing`, {
        preserveScroll: true,
        onSuccess: () => sentenceForm.reset('terms'),
    });
}

const warrantForm = useForm({ kind: 'arrest', stated_reason: '', max_hold_duration_hours: 72 });
function submitWarrant() {
    warrantForm.post(`/cases/${props.case.id}/warrants`, {
        preserveScroll: true,
        onSuccess: () => warrantForm.reset('stated_reason'),
    });
}

/* ---------------------------------- IO-1 lifecycle controls ------------ */
const hearingForm = useForm({});
function submitHearing() {
    hearingForm.post(`/cases/${props.case.id}/hearing`, { preserveScroll: true });
}

const deliberationForm = useForm({});
function submitDeliberation() {
    deliberationForm.post(`/cases/${props.case.id}/deliberation`, { preserveScroll: true });
}

const dismissForm = useForm({ reason: '' });
function submitDismiss() {
    dismissForm.post(`/cases/${props.case.id}/dismissal`, {
        preserveScroll: true,
        onSuccess: () => dismissForm.reset('reason'),
    });
}

/* ---------------------------------- IO-2 appeal (F-IND-027) ------------ */
/* A party to a decided/sentenced judgement may appeal. The control renders on
   a decided/sentenced original and is DISABLED (not hidden) with a status
   reason when the viewer is not a party. An appealed original shows its appeal
   link(s); an appeal case shows a link back to the original + the en-banc note. */
const canAppeal = computed(() => Boolean(props.can.appeal));
const isDecidedOrSentenced = computed(() => ['decided', 'sentenced'].includes(state.value));
const showAppealFiling = computed(() => !isAppeal.value && isDecidedOrSentenced.value);
const appealReason = computed(() =>
    canAppeal.value ? '' : 'Only a party to this case may appeal this judgement.',
);
const appealLinks = computed(() => props.case.appeals ?? []);
const APPEAL_OUTCOME_BADGE = {
    affirm: 'Affirmed',
    reverse: 'Reversed',
    remand: 'Remanded',
    vacate: 'Vacated — acquitted',
};
const appealForm = useForm({ grounds: '', statement: '' });
function submitAppeal() {
    appealForm.post(`/cases/${props.case.id}/appeals`, {
        preserveScroll: true,
        onSuccess: () => appealForm.reset('grounds', 'statement'),
    });
}

/* The verdict is NOT a form — it posts its own judge-only route. Outcomes are
   keyed on the case kind; a panel verdict records the vote counts, a jury
   verdict records unanimity. */
const CRIMINAL_OUTCOMES = [
    { value: 'guilty', label: 'Guilty' },
    { value: 'not_guilty', label: 'Not guilty' },
];
const CIVIL_OUTCOMES = [
    { value: 'liable', label: 'Liable' },
    { value: 'not_liable', label: 'Not liable' },
    { value: 'for_petitioner', label: 'For the petitioner' },
    { value: 'for_respondent', label: 'For the respondent' },
    { value: 'dismissed', label: 'Dismissed' },
];
const outcomeOptions = computed(() => (isCriminal.value ? CRIMINAL_OUTCOMES : CIVIL_OUTCOMES));
const verdictForm = useForm({
    decided_by: 'panel',
    outcome: isCriminal.value ? 'guilty' : 'liable',
    panel_vote_for: props.panel?.panelSize ?? 3,
    panel_vote_against: 0,
    jury_unanimous: true,
    summary: '',
});
function submitVerdict() {
    verdictForm.post(`/cases/${props.case.id}/verdict`, {
        preserveScroll: true,
        onSuccess: () => verdictForm.reset('summary'),
    });
}

/* Motion / evidence rulings (F-JDG-014) — a single inline draft, targeted at
   the row being ruled (JudicialNominations' router.post pattern). */
const rulingRow = ref(null);
const rulingChoice = ref('granted');
const rulingReason = ref('');
const rulingBusy = ref(false);
const rulingError = ref('');
const rulingNotice = ref('');
function openRuling(row, kind) {
    rulingRow.value = { key: `${kind}:${row.id ?? row.title}`, id: row.id ?? null, kind };
    rulingChoice.value = kind === 'evidence' ? 'admitted' : 'granted';
    rulingReason.value = '';
    rulingError.value = '';
    rulingNotice.value = '';
}
function submitRuling() {
    if (rulingBusy.value || !rulingRow.value || !rulingReason.value.trim()) {
        return;
    }
    router.post(
        `/cases/${props.case.id}/rulings`,
        {
            filing_kind: rulingRow.value.kind,
            references_filing_id: rulingRow.value.id,
            ruling: rulingChoice.value,
            ruling_reason: rulingReason.value.trim(),
        },
        {
            preserveScroll: true,
            onStart: () => { rulingBusy.value = true; rulingError.value = ''; rulingNotice.value = ''; },
            onFinish: () => { rulingBusy.value = false; },
            onError: (errors) => { rulingError.value = Object.values(errors)[0] || 'The ruling could not be filed. Please retry.'; },
            onSuccess: () => { rulingNotice.value = 'Ruling filed to the docket.'; rulingRow.value = null; rulingReason.value = ''; },
        },
    );
}
</script>

<template>
    <PageScaffold :surface="surface" :title="kase.title">
        <template #intro>
            The public record of one case before the court — every filing, ruling, and the panel
            that hears it. This page renders the live record and the surrounding context; the court
            advances the append-only record by acting through the engine, never a toggle.
        </template>

        <Link :href="'/rooms/court/' + kase.id" class="btn">{{ t('c_rooms.open_court', 'Enter live courtroom') }}</Link>

        <Banner v-if="constitutionError" tone="emergency" role="alert" title="The court action was rejected.">
            {{ constitutionError }}
        </Banner>
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- ============================================ header badges ===== -->
        <Card as="section">
            <p class="eyebrow" data-no-i18n>Case docket · {{ kase.docket_no }}</p>
            <div class="cluster" style="margin-block: var(--space-2)">
                <StatusBadge tone="neutral" icon="scale">{{ kase.kind }}</StatusBadge>
                <StatusBadge :tone="severityTone" icon="alert-triangle">{{ kase.severity }}</StatusBadge>
                <StatusBadge v-if="isFullCourt" tone="info" icon="users">Full court</StatusBadge>
                <StatusBadge v-else-if="kase.jury_entitled" tone="info" icon="users">Panel + jury</StatusBadge>
                <StatusBadge tone="neutral" icon="landmark">{{ kase.court.name }}</StatusBadge>
            </div>
            <p v-if="kase.accusation">{{ kase.accusation }}</p>
            <p class="citation" style="margin-block-start: var(--space-2)">
                Panel ≥3, odd, severity-scaled · Art. IV §4 · CLK-16
                <template v-if="isCriminal"> — criminal outcome carries the double-jeopardy flag · Art. II §8</template>
                <template v-if="kase.filed_by_label"> · {{ kase.filed_by_label }}</template>
            </p>
        </Card>

        <!-- ================================== the lifecycle (centerpiece) = -->
        <Card as="section" :title="`Lifecycle — stage ${kase.current_stage} of ${stages.length}`">
            <CaseLifecycle
                :case="kase"
                :interactive="isDemoMode"
                :machine="machine"
                :stages="stages"
                :stage-state-map="stageStateMap"
            >
                <!-- Stage 3 — the conflict-screened panel (engine snapshots) -->
                <template #stage-3>
                    <PanelTable
                        v-if="panel"
                        :seats="panel.seats"
                        :severity="panel.severity"
                        :panel-size="panel.panelSize"
                        :is-full-court="panel.isFullCourt"
                        :rule="panel.rule"
                    />
                    <Banner v-else tone="info" role="status" title="Panel pending.">
                        The bench is seated when the court accepts the case and classifies severity
                        (F-JDG-001). Conflicted judges are excluded and the draw re-runs.
                    </Banner>
                </template>

                <!-- Stage 4 — pre-trial motions docket -->
                <template #stage-4>
                    <DataTable
                        v-if="motions.length"
                        :columns="motionColumns"
                        :rows="motions"
                        caption="Pre-trial motions and rulings"
                    >
                        <template #cell-ruling="{ row }">
                            <StatusBadge v-if="row.ruling" :tone="RULING_TONE[row.ruling] ?? 'neutral'">
                                {{ RULING_LABEL[row.ruling] ?? row.ruling }}
                            </StatusBadge>
                            <span v-else class="gloss">pending</span>
                            <span v-if="row.ruling_reason" class="citation" style="display: block">{{ row.ruling_reason }}</span>
                            <!-- F-JDG-014 — the court rules on this motion (appends a follow-up) -->
                            <template v-if="canRuleFilings && !row.ruling">
                                <button
                                    v-if="!rulingRow || rulingRow.key !== 'motion:' + (row.id ?? row.title)"
                                    type="button"
                                    class="ruling-toggle"
                                    @click="openRuling(row, 'motion')"
                                >
                                    Rule on this motion
                                </button>
                                <form v-else class="ruling-form" :aria-busy="rulingBusy" @submit.prevent="submitRuling">
                                    <label :for="'motion-ruling-' + (row.id ?? row.title)">Ruling</label>
                                    <select :id="'motion-ruling-' + (row.id ?? row.title)" v-model="rulingChoice">
                                        <option value="granted">Grant</option>
                                        <option value="denied">Deny</option>
                                    </select>
                                    <label :for="'motion-reason-' + (row.id ?? row.title)">Written reason</label>
                                    <textarea :id="'motion-reason-' + (row.id ?? row.title)" v-model="rulingReason" rows="2" required />
                                    <button type="submit" :disabled="rulingBusy || !rulingReason.trim()">File ruling</button>
                                </form>
                            </template>
                        </template>
                    </DataTable>
                    <p v-else class="gloss">No pre-trial motions on the docket.</p>
                    <p v-if="rulingNotice" role="status">{{ rulingNotice }}</p>
                    <p v-if="rulingError" role="alert">{{ rulingError }}</p>
                </template>

                <!-- Stage 5 — evidence docket -->
                <template #stage-5>
                    <DataTable
                        v-if="evidence.length"
                        :columns="evidenceColumns"
                        :rows="evidence"
                        caption="Exhibits with admissibility rulings"
                    >
                        <template #cell-ruling="{ row }">
                            <StatusBadge v-if="row.ruling" :tone="RULING_TONE[row.ruling] ?? 'neutral'">
                                {{ RULING_LABEL[row.ruling] ?? row.ruling }}
                            </StatusBadge>
                            <span v-else class="gloss">pending</span>
                            <span v-if="row.ruling_reason" class="citation" style="display: block">{{ row.ruling_reason }}</span>
                            <!-- F-JDG-014 — the court rules on this exhibit's admissibility -->
                            <template v-if="canRuleFilings && !row.ruling">
                                <button
                                    v-if="!rulingRow || rulingRow.key !== 'evidence:' + (row.id ?? row.title)"
                                    type="button"
                                    class="ruling-toggle"
                                    @click="openRuling(row, 'evidence')"
                                >
                                    Rule on admissibility
                                </button>
                                <form v-else class="ruling-form" :aria-busy="rulingBusy" @submit.prevent="submitRuling">
                                    <label :for="'evidence-ruling-' + (row.id ?? row.title)">Admissibility</label>
                                    <select :id="'evidence-ruling-' + (row.id ?? row.title)" v-model="rulingChoice">
                                        <option value="admitted">Admit</option>
                                        <option value="excluded">Exclude</option>
                                    </select>
                                    <label :for="'evidence-reason-' + (row.id ?? row.title)">Written reason</label>
                                    <textarea :id="'evidence-reason-' + (row.id ?? row.title)" v-model="rulingReason" rows="2" required />
                                    <button type="submit" :disabled="rulingBusy || !rulingReason.trim()">File ruling</button>
                                </form>
                            </template>
                        </template>
                    </DataTable>
                    <p v-else class="gloss">No exhibits on the evidence docket.</p>
                </template>

                <!-- Stage 6 — the jury draw -->
                <template #stage-6>
                    <Banner
                        v-if="jury"
                        tone="info"
                        role="status"
                        title="Random draw complete — voir dire under way"
                    >
                        {{ jury.jurors }} jurors + {{ jury.alternates }} alternates drawn at random from
                        {{ jury.pool_label }}. The selection seed is published to the
                        <Link :href="jury.seed_audit_href">audit chain</Link> — anyone can verify the draw.
                        <span class="citation" data-no-i18n>Art. IV §4 (jury of peers) · WF-JUD-04</span>
                    </Banner>
                    <Banner v-else-if="kase.jury_entitled" tone="info" role="status" title="Jury pending.">
                        This criminal case is jury-entitled — the jury is drawn at random once the bench
                        is seated (F-JDG-002). The selection seed publishes to the audit chain.
                    </Banner>
                    <p v-else class="gloss">No jury — a jury attaches only to a jury-entitled criminal case.</p>

                    <p style="margin-block-start: var(--space-3)">
                        <Link href="/judiciary/jury">See this stage as a summoned juror →</Link>
                    </p>
                </template>

                <!-- Stage 8 — the locked deliberation spaces -->
                <template #stage-8>
                    <div class="grid-2">
                        <Card inset title="Judges' chambers">
                            <StatusBadge tone="neutral" icon="lock">Locked — panel judges only</StatusBadge>
                            <p style="margin-block-start: var(--space-2); font-size: var(--text-sm)">
                                Access-controlled room for the panel judges only. Parties and advocates cannot enter.
                            </p>
                        </Card>
                        <Card inset title="Jury room">
                            <StatusBadge tone="neutral" icon="lock">Locked — opens at deliberation</StatusBadge>
                            <p style="margin-block-start: var(--space-2); font-size: var(--text-sm)">
                                The jury deliberates separately — no judges, no parties, no contact. Deliberation is the
                                only unrecorded space; the verdict itself is recorded.
                            </p>
                        </Card>
                    </div>
                    <p class="citation" style="margin-block-start: var(--space-3)">
                        Separate deliberation preserves the independence of the jury of peers · Art. IV §4
                    </p>
                </template>

                <!-- Stage 9 — judgement: the double-jeopardy flag + sentence/warrant -->
                <template #stage-9>
                    <Banner v-if="isCriminal" tone="warning" role="note" title="Criminal outcome — double-jeopardy flag attaches">
                        Whichever way the verdict falls, the outcome record carries the double-jeopardy flag: the
                        accused can never be prosecuted again for this same accusation. The flag is machine-enforced
                        at filing time. <span class="citation" data-no-i18n>Art. II §8</span>
                    </Banner>
                    <p style="margin-block-start: var(--space-3)">
                        On a guilty verdict the panel issues a sentencing order (F-JDG-009); any arrest,
                        search, or seizure connected to enforcement needs a warrant with a stated reason
                        and duration (F-JDG-010 · Art. II §8). The court's actions render below.
                    </p>
                </template>

                <!-- Stage 10 — opinion publication -->
                <template #stage-10>
                    <p>
                        The panel publishes its opinion to the public record, linked to the case and to every law it
                        interprets. Opinions are <strong>commentary on the law as written or edited</strong> — only the
                        Art. IV §5 process can change the law's text.
                    </p>
                    <p class="citation" style="margin-block: var(--space-2)">
                        <HardenedChip>Opinion linked as commentary · Art. IV §4–§5</HardenedChip>
                    </p>

                    <p style="margin-block-start: var(--space-3)">
                        <Link :href="`/judiciaries/${kase.judiciary_id}/challenges`">
                            See how a finding changes the law — the Art. IV §5 tracker →
                        </Link>
                    </p>
                </template>
            </CaseLifecycle>
        </Card>

        <!-- ========================= appeal (IO-2, Art. II §8) ========== -->
        <Card v-if="isAppeal || showAppealFiling || appealLinks.length" as="section" title="Appeal" class="appeal">
            <!-- This case IS an appeal — link back to the original + en-banc note -->
            <template v-if="isAppeal">
                <p>
                    This case is an appeal of
                    <Link v-if="kase.appeal_of" :href="kase.appeal_of.href">{{ kase.appeal_of.docket_number }}</Link><span v-else>the original case</span>.
                    <template v-if="kase.en_banc"> It is heard by the same court sitting en banc — there is no parent court.</template>
                    <template v-else> It is heard by the parent court.</template>
                </p>
                <p class="citation">A criminal appeal may only affirm or vacate — never a re-trial · Art. II §8.</p>
            </template>

            <!-- A decided/sentenced original — the party's appeal-filing control -->
            <template v-else-if="showAppealFiling">
                <p>
                    A party to this judgement may appeal on a proven contradiction in law, or an error in the
                    case that made the judgement invalid. The appeal opens a new case at the parent court (or the
                    same court en banc); this judgement rests as appealed and its verdict is preserved · Art. II §8.
                </p>
                <form class="appeal-form" novalidate :aria-busy="appealForm.processing" @submit.prevent="submitAppeal">
                    <Field label="Grounds for appeal" hint="The contradiction in law, or the error in the case." :error="appealForm.errors.grounds">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="appealForm.grounds" class="field-input" rows="3" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <Field label="Statement (optional)" :error="appealForm.errors.statement">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="appealForm.statement" class="field-input" rows="2" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <div class="cluster">
                        <button type="submit" class="btn btn-primary" :disabled="!canAppeal || appealForm.processing || !appealForm.grounds.trim()">
                            {{ appealForm.processing ? 'Filing…' : 'Appeal this judgement' }}
                        </button>
                    </div>
                    <p v-if="appealReason" class="gloss" role="status">{{ appealReason }}</p>
                </form>
            </template>

            <!-- An appealed original — link to its appeal case(s) -->
            <template v-if="appealLinks.length">
                <p style="margin-block-start: var(--space-3)">This judgement has been appealed:</p>
                <ul class="appeal-list">
                    <li v-for="a in appealLinks" :key="a.id">
                        <Link :href="a.href">{{ a.docket_number }}</Link>
                        <StatusBadge tone="neutral">{{ a.status }}</StatusBadge>
                        <StatusBadge v-if="a.outcome" tone="info">{{ APPEAL_OUTCOME_BADGE[a.outcome] ?? a.outcome }}</StatusBadge>
                    </li>
                </ul>
            </template>
        </Card>

        <!-- ================= case proceedings (IO-1, R-19/R-20) ========== -->
        <Card v-if="isCourt" as="section" title="Case proceedings" class="proceedings">
            <p class="citation" style="margin-block-end: var(--space-3)">
                The court advances the case through its lifecycle — each control is enabled only at the
                state where the act is legal; the engine re-asserts the edge on every filing. Art. IV §4.
            </p>
            <div class="stack" style="gap: var(--space-4)">
                <!-- F-JDG-011 — open the hearing (paneled/jury_empaneled → heard) -->
                <FormCard
                    v-if="surfaceForm('F-JDG-011')"
                    :form="surfaceForm('F-JDG-011')"
                    :inertia-form="hearingForm"
                    :disabled="!canAdvanceHearing"
                    submit-label="Open the hearing"
                    @submit="submitHearing"
                >
                    <p class="citation">Opens arguments once the panel (and any jury) is seated · Art. IV §4.</p>
                    <p v-if="!canAdvanceHearing" class="gloss" role="status">Available once the case is paneled (and any jury empaneled).</p>
                </FormCard>

                <!-- F-JDG-012 — submit to deliberation (heard → deliberation) -->
                <FormCard
                    v-if="surfaceForm('F-JDG-012')"
                    :form="surfaceForm('F-JDG-012')"
                    :inertia-form="deliberationForm"
                    :disabled="!canDeliberate"
                    submit-label="Send to deliberation"
                    @submit="submitDeliberation"
                >
                    <p class="citation">Closes arguments; chambers and the jury room open. Deliberation is the only unrecorded space · Art. IV §4.</p>
                    <p v-if="!canDeliberate" class="gloss" role="status">Available once the hearing is under way.</p>
                </FormCard>

                <!-- The VERDICT — a judge-only CaseService transition, not a form -->
                <Card inset as="section" title="Record the verdict" class="verdict-form">
                    <p class="citation" style="margin-block-end: var(--space-2)">
                        The verdict is recorded by a judge seated on this case's panel — a judge-only act, not a form.
                        A criminal verdict locks double jeopardy · Art. II §8 · Art. IV §4.
                    </p>
                    <form novalidate :aria-busy="verdictForm.processing" @submit.prevent="submitVerdict">
                        <div class="grid-2">
                            <Field label="Recorded by" :error="verdictForm.errors.decided_by">
                                <template #control="{ id, describedBy }">
                                    <select :id="id" v-model="verdictForm.decided_by" class="select" :aria-describedby="describedBy">
                                        <option value="panel">Panel</option>
                                        <!-- Jury verdict only when a jury actually sat; the server refuses it otherwise · Art. IV §4. -->
                                        <option v-if="jury" value="jury">Jury</option>
                                    </select>
                                </template>
                            </Field>
                            <Field label="Outcome" :error="verdictForm.errors.outcome">
                                <template #control="{ id, describedBy }">
                                    <select :id="id" v-model="verdictForm.outcome" class="select" :aria-describedby="describedBy">
                                        <option v-for="opt in outcomeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                </template>
                            </Field>
                        </div>
                        <div v-if="verdictForm.decided_by === 'panel'" class="grid-2">
                            <Field label="Votes for" hint="For + against must equal the panel size; the majority carries the outcome." :error="verdictForm.errors.panel_vote_for">
                                <template #control="{ id, describedBy }">
                                    <input :id="id" v-model.number="verdictForm.panel_vote_for" type="number" min="0" class="field-input" :aria-describedby="describedBy" />
                                </template>
                            </Field>
                            <Field label="Votes against" :error="verdictForm.errors.panel_vote_against">
                                <template #control="{ id, describedBy }">
                                    <input :id="id" v-model.number="verdictForm.panel_vote_against" type="number" min="0" class="field-input" :aria-describedby="describedBy" />
                                </template>
                            </Field>
                        </div>
                        <Field v-else label="Jury unanimous" hint="A jury verdict is recorded only when the jury is unanimous." :error="verdictForm.errors.jury_unanimous">
                            <template #control="{ id, describedBy }">
                                <input :id="id" v-model="verdictForm.jury_unanimous" type="checkbox" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                        <Field label="Summary (optional)" :error="verdictForm.errors.summary">
                            <template #control="{ id, describedBy }">
                                <textarea :id="id" v-model="verdictForm.summary" class="field-input" rows="2" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                        <div class="cluster">
                            <button type="submit" class="btn btn-primary" :disabled="!canRecordVerdict || verdictForm.processing">
                                {{ verdictForm.processing ? 'Recording…' : 'Record verdict' }}
                            </button>
                        </div>
                        <p v-if="!canRecordVerdict" class="gloss" role="status">Available once the case is in deliberation.</p>
                    </form>
                </Card>

                <!-- F-JDG-013 — dismiss (filed/accepted → dismissed) -->
                <FormCard
                    v-if="surfaceForm('F-JDG-013')"
                    :form="surfaceForm('F-JDG-013')"
                    :inertia-form="dismissForm"
                    :disabled="!canDismiss"
                    submit-label="Dismiss the case"
                    @submit="submitDismiss"
                >
                    <Field label="Reason for dismissal" hint="The public record names why the case ended." :error="dismissForm.errors.reason">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="dismissForm.reason" class="field-input" rows="2" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <p v-if="!canDismiss" class="gloss" role="status">Available before the panel is seated (filed or accepted).</p>
                </FormCard>
            </div>
        </Card>

        <!-- ====================== court actions (R-19/R-20) ============== -->
        <Card v-if="hasCourtAction" as="section" title="Court actions">
            <p class="citation" style="margin-block-end: var(--space-3)">
                The court advances the append-only record by filing through the engine — each action
                is accepted only at the state where it is legal (the engine is the boundary). Art. IV §4.
            </p>
            <div class="stack" style="gap: var(--space-4)">
                <!-- F-JDG-001 — accept + classify + seat the panel -->
                <FormCard
                    v-if="canAccept && surfaceForm('F-JDG-001')"
                    :form="surfaceForm('F-JDG-001')"
                    :inertia-form="acceptForm"
                    submit-label="Accept and seat the panel"
                    @submit="submitAccept"
                >
                    <Field
                        label="Court severity classification"
                        hint="The court's classification drives the panel size — not the filer's claim."
                        :error="acceptForm.errors.court_severity"
                    >
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="acceptForm.court_severity" class="select" :aria-describedby="describedBy">
                                <option value="minor">Minor</option>
                                <option value="moderate">Moderate</option>
                                <option value="serious">Serious</option>
                                <option value="constitutional_major">Major constitutional question</option>
                            </select>
                        </template>
                    </Field>
                </FormCard>

                <!-- F-JDG-002 — order the random jury draw -->
                <FormCard
                    v-if="canOrderJury && surfaceForm('F-JDG-002')"
                    :form="surfaceForm('F-JDG-002')"
                    :inertia-form="juryForm"
                    submit-label="Order the jury draw"
                    @submit="submitJury"
                >
                    <div class="grid-2">
                        <Field label="Jurors" :error="juryForm.errors.seats">
                            <template #control="{ id, describedBy }">
                                <input :id="id" v-model.number="juryForm.seats" type="number" min="1" class="field-input" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                        <Field label="Alternates" :error="juryForm.errors.alternates">
                            <template #control="{ id, describedBy }">
                                <input :id="id" v-model.number="juryForm.alternates" type="number" min="0" class="field-input" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                    </div>
                    <p class="citation">The selection seed publishes to the audit chain — anyone can verify the draw · Art. IV §4.</p>
                </FormCard>

                <!-- F-JDG-009 — sentencing order (guilty criminal verdict only) -->
                <FormCard
                    v-if="canSentence && surfaceForm('F-JDG-009')"
                    :form="surfaceForm('F-JDG-009')"
                    :inertia-form="sentenceForm"
                    submit-label="Issue sentencing order"
                    @submit="submitSentence"
                >
                    <Field label="Sentence terms" :error="sentenceForm.errors.terms">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="sentenceForm.terms" class="field-input" rows="3" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <p class="citation">Issues only on a guilty criminal verdict · Art. IV §4.</p>
                </FormCard>

                <!-- F-JDG-010 — warrant (Art. II §8 facts) -->
                <FormCard
                    v-if="canWarrant && surfaceForm('F-JDG-010')"
                    :form="surfaceForm('F-JDG-010')"
                    :inertia-form="warrantForm"
                    submit-label="Issue warrant"
                    @submit="submitWarrant"
                >
                    <div class="grid-2">
                        <Field label="Warrant kind" :error="warrantForm.errors.kind">
                            <template #control="{ id, describedBy }">
                                <select :id="id" v-model="warrantForm.kind" class="select" :aria-describedby="describedBy">
                                    <option value="arrest">Arrest</option>
                                    <option value="search">Search</option>
                                    <option value="seizure">Seizure</option>
                                </select>
                            </template>
                        </Field>
                        <Field
                            v-if="warrantForm.kind === 'arrest'"
                            label="Max hold (hours)"
                            hint="An arrest warrant must state the maximum duration · Art. II §8."
                            :error="warrantForm.errors.max_hold_duration_hours"
                        >
                            <template #control="{ id, describedBy }">
                                <input :id="id" v-model.number="warrantForm.max_hold_duration_hours" type="number" min="1" class="field-input" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                    </div>
                    <Field label="Stated reason" hint="Every warrant must establish its reason · Art. II §8." :error="warrantForm.errors.stated_reason">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="warrantForm.stated_reason" class="field-input" rows="2" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                </FormCard>

                <!-- F-JDG-003 — opinion (commentary on the law; closes the case) -->
                <FormCard
                    v-if="canOpine && surfaceForm('F-JDG-003')"
                    :form="surfaceForm('F-JDG-003')"
                    :inertia-form="opinionForm"
                    submit-label="Publish opinion"
                    @submit="submitOpinion"
                >
                    <div class="grid-2">
                        <Field label="Opinion kind" :error="opinionForm.errors.kind">
                            <template #control="{ id, describedBy }">
                                <select :id="id" v-model="opinionForm.kind" class="select" :aria-describedby="describedBy">
                                    <option value="majority">Majority</option>
                                    <option value="concurrence">Concurrence</option>
                                    <option value="dissent">Dissent</option>
                                </select>
                            </template>
                        </Field>
                        <Field label="Title" :error="opinionForm.errors.title">
                            <template #control="{ id, describedBy }">
                                <input :id="id" v-model="opinionForm.title" class="field-input" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                    </div>
                    <Field label="Opinion body" :error="opinionForm.errors.body">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="opinionForm.body" class="field-input" rows="4" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <!-- IO-2 — on an appeal case the opinion records the appellate outcome. -->
                    <Field
                        v-if="isAppeal"
                        label="Appellate outcome"
                        hint="Civil: affirm, reverse or remand. Criminal: affirm or vacate only — never a re-trial (Art. II §8)."
                        :error="opinionForm.errors.appeal_outcome"
                    >
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="opinionForm.appeal_outcome" class="select" :aria-describedby="describedBy">
                                <option v-for="o in appealOutcomes" :key="o" :value="o">{{ APPEAL_OUTCOME_LABEL[o] ?? o }}</option>
                            </select>
                        </template>
                    </Field>
                    <p class="citation">Commentary on the law as written or edited; only the Art. IV §5 process changes a law's text.</p>
                </FormCard>
            </div>
        </Card>

        <template #about>
            <p>
                The case lifecycle is WF-JUD-03; jury paneling is WF-JUD-04; a constitutional finding branches
                into WF-JUD-05. Major constitutional questions take the full court instead of a severity-scaled
                panel (CLK-16, hardened). The Case state machine renders live above.
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
/* IO-1 controls — 44px targets (matching JudicialNominations.vue). */
.verdict-form form { display: grid; gap: 0.65rem; }
.verdict-form button,
.verdict-form select,
.verdict-form input,
.verdict-form textarea,
.ruling-form button,
.ruling-form select,
.ruling-form input,
.ruling-form textarea,
.appeal-form button,
.appeal-form select,
.appeal-form input,
.appeal-form textarea,
.ruling-toggle { min-block-size: 44px; font: inherit; }
.ruling-form { display: grid; gap: 0.5rem; margin-block-start: 0.5rem; max-inline-size: 28rem; }
.ruling-toggle { inline-size: fit-content; cursor: pointer; margin-block-start: 0.5rem; }
.appeal-form { display: grid; gap: 0.65rem; margin-block-start: 0.5rem; }
.appeal-list { display: grid; gap: 0.35rem; margin-block-start: 0.5rem; padding-inline-start: 1.1rem; }
</style>
