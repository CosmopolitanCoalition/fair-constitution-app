<script setup>
/**
 * Judiciary/Art4Section5Tracker — THE Phase E exit-criterion component
 * (FE-E1; PHASE_E_DESIGN_frontend.md §A.3; constitutional-challenge.html
 * renderTracker()). Renders the Art. IV §5 pipeline end to end: finding →
 * remedy → window → three paths → direct law edit.
 *
 * Composes the already-ported kit — StateStrip (challenge ESM), the F-JDG
 * FormCards, a Ui/Stepper three-path overview, VoteTally (the F-LEG-035
 * supermajority override), and LawDiff (the judicial_remedy law version,
 * del/ins) + the preserved-history link.
 *
 * CONSTITUTIONAL POSTURE — the record is a pure renderer: every threshold,
 * the override `required` count, the CLK-11/CLK-12 due dates, and the
 * `applied` boolean are ENGINE snapshots from constitutional_challenges /
 * chamber_votes / clock_timers.override_value / the judicial_remedy
 * LawVersion. Feed `override.required=99` and it honestly displays 99 (the
 * VoteTally pure-renderer discipline).
 *
 * OUTCOME CONTROLS (IO-3, operator ruling 2026-09-13) — the four existing
 * handlers post from this tracker, each control enabled only in its lawful
 * state by a `can` HINT computed server-side from status + the viewer's seat
 * / membership: the finding (F-JDG-004) and the remedy recommendation
 * (F-JDG-005) for a seated judge; the supermajority override (F-LEG-035, Path
 * B) for a member of the offending law's legislature within the veto window;
 * the direct judicial remedy (F-JDG-006, Path C) for a seated judge once both
 * windows close (the CLK-11 sweep applies it automatically too); and a
 * "propose amendment bill" link (Path A) that prefills targets_challenge_id.
 * The ENGINE is the 422 boundary — the flags drive enabled state only and
 * never re-derive a threshold or a window client-side.
 *
 * Classes: kit classes plus a small scoped block for the control forms.
 */
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import LawDiff from '@/Components/Ui/LawDiff.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';

const props = defineProps({
    /**
     * Live constitutional_challenges row joined to its finding/remedy/
     * override/judicial_remedy version (server-shaped). null -> empty state.
     * Shape:
     *   id, name, law:{id, name, href}, filed_by_label, filed_at,
     *   court:{name}, is_major:bool, full_court_size, writing_judge:{name},
     *   state (challenge ESM resting state),
     *   finding:{ form_card, text } (F-JDG-004),
     *   remedy:{ form_card, text, timeframe_days, timeframe_due_on, clk,
     *            veto_window_days, veto_closes_on, veto_clk, tz } (F-JDG-005),
     *   override:{ form_card, vote:VoteTallyProps|null, required, serving,
     *              yes, closed:bool } (F-LEG-035),
     *   resolution: 'window_open'|'amended'|'overridden'|'applied',
     *   bill_href, enforcement:{ href },
     *   judicial_remedy_form_card (F-JDG-006),
     *   remedy_diff:{ segments:[LawDiff segs], applied:bool, version_no,
     *                 prior_version_no, history_href }
     */
    challenge: { type: Object, default: null },
    /** Challenge ESM states (config/cga/state_machines.php). Required for the strip. */
    machine: { type: Array, default: () => [] },
    /** Empty-state filing FormCard record (F-IND-016) — from SurfaceMeta. */
    fileForm: { type: Object, default: null },
    /**
     * IO-3 outcome gate hints (server-computed, enabled-state only):
     * { isSeatedJudge, isLegislatureMember, finding, recommend, override,
     *   remedy, proposeAmendment }. The engine remains the 422 boundary.
     */
    can: { type: Object, default: () => ({}) },
});

const STAGE_GLOSS =
    'The finding lands on the legislature as a mandatory session priority — constitutional ' +
    'matters precede the general agenda (WF-LEG-05).';

const ch = computed(() => props.challenge);
const remedy = computed(() => ch.value?.remedy ?? {});
const override = computed(() => ch.value?.override ?? {});
const diff = computed(() => ch.value?.remedy_diff ?? null);
const resolution = computed(() => ch.value?.resolution ?? 'window_open');

/* ------------------------------------------ IO-3 outcome controls --------- */
const can = computed(() => props.can ?? {});
const base = computed(() => `/constitutional-challenges/${ch.value?.id}`);

const findingForm = reactive({ finds_contradiction: 'true', opinion_text: '', full_court: false });
const recommendForm = reactive({ remedy_kind: 'modify', recommended_text: '', rationale_text: '', remedy_timeframe_days: 30, veto_window_days: 30 });
const overrideForm = reactive({ dissent_text: '' });

const busy = ref('');
const error = ref('');
const notice = ref('');

function post(action, url, data) {
    if (busy.value) return;
    router.post(url, data, {
        preserveScroll: true,
        onStart: () => { busy.value = action; error.value = ''; notice.value = ''; },
        onFinish: () => { busy.value = ''; },
        onError: (errs) => { error.value = errs.constitution || Object.values(errs)[0] || 'The action was refused. Please retry.'; },
        onSuccess: () => { notice.value = 'Recorded on the public register — the tracker reflects the new state.'; },
    });
}

function submitFinding() {
    post('finding', `${base.value}/finding`, {
        finds_contradiction: findingForm.finds_contradiction === 'true',
        opinion_text: findingForm.opinion_text,
        full_court: findingForm.full_court,
    });
}
function submitRecommend() {
    post('recommend', `${base.value}/remedy-recommendation`, {
        remedy_kind: recommendForm.remedy_kind,
        recommended_text: recommendForm.remedy_kind === 'modify' ? recommendForm.recommended_text : '',
        rationale_text: recommendForm.rationale_text,
        remedy_timeframe_days: recommendForm.remedy_timeframe_days,
        veto_window_days: recommendForm.veto_window_days,
    });
}
function submitOverride() {
    post('override', `${base.value}/override`, { dissent_text: overrideForm.dissent_text });
}
function submitRemedy() {
    post('remedy', `${base.value}/remedy`, {});
}

/* Pipeline overview steps — Finding+Remedy → Legislative window → Resolved. */
const steps = computed(() => {
    const resolved = resolution.value !== 'window_open';
    return [
        { label: 'Finding + remedy', icon: 'scale', state: 'done' },
        { label: 'Legislative window', icon: 'clock', state: resolved ? 'done' : 'active' },
        { label: 'Resolved', icon: 'check', state: resolved ? 'done' : 'pending' },
    ];
});

/* Per-path status badge: the engine's resolution decides, never the UI. */
function pathBadge(path) {
    const r = resolution.value;
    if (path === 'A') {
        return r === 'amended'
            ? { tone: 'success', icon: 'check', text: 'Resolved' }
            : r === 'window_open'
              ? { tone: 'info', icon: 'clock', text: 'Open' }
              : { tone: 'neutral', icon: 'x', text: 'Closed — resolved on another path' };
    }
    if (path === 'B') {
        return r === 'overridden'
            ? { tone: 'success', icon: 'check', text: 'Resolved' }
            : r === 'window_open'
              ? { tone: 'info', icon: 'clock', text: 'Open' }
              : { tone: 'neutral', icon: 'x', text: 'Closed — resolved on another path' };
    }
    return r === 'applied'
        ? { tone: 'success', icon: 'check', text: 'Resolved' }
        : r === 'window_open'
          ? { tone: 'neutral', icon: 'clock', text: 'Pending window close' }
          : { tone: 'neutral', icon: 'x', text: 'Closed — resolved on another path' };
}
</script>

<template>
    <!-- =================================================== EMPTY STATE === -->
    <Card v-if="!challenge" as="section" title="No constitutional challenge is open in your jurisdictions">
        <div class="cluster" style="margin-block-end: var(--space-3)">
            <StatusBadge tone="neutral" icon="info">No open challenge</StatusBadge>
        </div>
        <p>
            When a court issues a finding under Art. IV §5, this tracker shows the finding, the
            recommended remedy, both clocks (CLK-11 / CLK-12), and the three resolution paths live.
        </p>
        <div v-if="fileForm" class="stack" style="gap: var(--space-3); margin-block-start: var(--space-4)">
            <FormCard :form="fileForm">
                <slot name="file-fields" />
            </FormCard>
        </div>
    </Card>

    <!-- ====================================================== TRACKER ==== -->
    <div v-else class="stack" style="gap: var(--space-5)">
        <!-- 1 · Window banner + the two clocks -->
        <Banner
            tone="warning"
            role="status"
            :title="`Legislative window open — override closes ${remedy.veto_closes_on}`"
        >
            The legislature has {{ remedy.timeframe_days }} days to modify or remove the law
            (CLK-12, due {{ remedy.timeframe_due_on }}) and {{ remedy.veto_window_days }} days to
            override ({{ remedy.veto_clk || 'CLK-11' }}).
            <span class="citation" data-no-i18n>{{ remedy.tz || 'stored as UTC' }}</span>
        </Banner>

        <!-- 2 · Challenge summary + the F-IND-016 entry point -->
        <Card as="section" title="Active challenge">
            <h2 style="margin-block: var(--space-1) var(--space-2)">{{ ch.name }}</h2>
            <p>
                <strong style="color: var(--gov-fg)">Law challenged:</strong>
                <a v-if="ch.law?.href" :href="ch.law.href">{{ ch.law?.name }}</a>
                <template v-else>{{ ch.law?.name }}</template>
            </p>
            <p>
                <strong style="color: var(--gov-fg)">Filed:</strong> {{ ch.filed_at }} by
                {{ ch.filed_by_label }} — any inhabitant may file; no standing gatekeeper beyond
                jurisdictional association.
            </p>
            <p>
                <strong style="color: var(--gov-fg)">Heard by:</strong>
                <template v-if="ch.is_major">
                    the full court — all {{ ch.full_court_size }} judges · CLK-16,
                    {{ ch.writing_judge?.name }} writing.
                    <HardenedChip />
                </template>
                <template v-else>
                    a severity-scaled panel of the {{ ch.court?.name }}, {{ ch.writing_judge?.name }} writing.
                </template>
            </p>
            <p class="citation">Right to challenge · Art. IV §5</p>
            <div v-if="fileForm" class="stack" style="gap: var(--space-3); margin-block-start: var(--space-3)">
                <FormCard :form="fileForm">
                    <slot name="file-fields" />
                </FormCard>
            </div>
        </Card>

        <!-- 3 · Challenge ESM state strip -->
        <Card as="section" inset>
            <span class="eyebrow">Constitutional Challenge state machine</span>
            <div style="margin-block-start: var(--space-2)">
                <StateStrip :states="machine" :current="ch.state" />
            </div>
        </Card>

        <!-- 4 · Finding & remedy — reference cards (the court's record, not forms here) -->
        <Card as="section" title="Finding & recommended remedy">
            <div class="grid-2">
                <div v-if="ch.finding?.form_card" class="card card--inset">
                    <div class="cluster" style="justify-content: space-between; align-items: baseline">
                        <strong style="color: var(--gov-fg)">{{ ch.finding.form_card.name }}</strong>
                        <FormChip :form-id="ch.finding.form_card.id" :alias="ch.finding.form_card.alias" />
                    </div>
                    <p class="citation" style="margin-block: var(--space-1)">{{ ch.finding.form_card.citation }}</p>
                    <p style="font-size: var(--text-sm); color: var(--gov-fg)">“{{ ch.finding.text }}”</p>
                </div>
                <div v-if="remedy.form_card" class="card card--inset">
                    <div class="cluster" style="justify-content: space-between; align-items: baseline">
                        <strong style="color: var(--gov-fg)">{{ remedy.form_card.name }}</strong>
                        <FormChip :form-id="remedy.form_card.id" :alias="remedy.form_card.alias" />
                    </div>
                    <p class="citation" style="margin-block: var(--space-1)">{{ remedy.form_card.citation }}</p>
                    <p style="font-size: var(--text-sm); color: var(--gov-fg)">
                        “{{ remedy.text }}.” Timeframe: {{ remedy.timeframe_days }} days
                        ({{ remedy.clk || 'CLK-12' }}) · veto window: {{ remedy.veto_window_days }} days
                        ({{ remedy.veto_clk || 'CLK-11' }}).
                    </p>
                </div>
            </div>
            <p class="gloss" style="margin-block-start: var(--space-3)">{{ STAGE_GLOSS }}</p>

            <!-- IO-3 · a seated judge records the finding, then the remedy -->
            <div v-if="can.isSeatedJudge" class="a4-controls">
                <form class="a4-control" :aria-busy="busy === 'finding'" @submit.prevent="submitFinding">
                    <h4>Record the constitutional finding</h4>
                    <label :for="`a4-finds-${ch.id}`">Determination</label>
                    <select :id="`a4-finds-${ch.id}`" v-model="findingForm.finds_contradiction">
                        <option value="true">A contradiction is found (opens the remedy step)</option>
                        <option value="false">No contradiction (dismiss the challenge)</option>
                    </select>
                    <label :for="`a4-opinion-${ch.id}`">Opinion</label>
                    <textarea :id="`a4-opinion-${ch.id}`" v-model="findingForm.opinion_text" rows="3" maxlength="10000" />
                    <label class="a4-check"><input v-model="findingForm.full_court" type="checkbox" /> Heard by the full court</label>
                    <button type="submit" :disabled="busy !== '' || !can.finding">Record finding</button>
                    <p v-if="!can.finding" role="status">A finding is recorded while the challenge is under review (Art. IV §5.2).</p>
                </form>

                <form class="a4-control" :aria-busy="busy === 'recommend'" @submit.prevent="submitRecommend">
                    <h4>Recommend the remedy and set the windows</h4>
                    <label :for="`a4-kind-${ch.id}`">Remedy</label>
                    <select :id="`a4-kind-${ch.id}`" v-model="recommendForm.remedy_kind">
                        <option value="modify">Modify the law (provide replacement text)</option>
                        <option value="remove">Remove the law (repeal)</option>
                    </select>
                    <template v-if="recommendForm.remedy_kind === 'modify'">
                        <label :for="`a4-text-${ch.id}`">Replacement text</label>
                        <textarea :id="`a4-text-${ch.id}`" v-model="recommendForm.recommended_text" rows="3" maxlength="20000" />
                    </template>
                    <label :for="`a4-rationale-${ch.id}`">Why this makes the law non-contradictory</label>
                    <textarea :id="`a4-rationale-${ch.id}`" v-model="recommendForm.rationale_text" rows="2" maxlength="10000" />
                    <label :for="`a4-tf-${ch.id}`">Remedy timeframe (days · CLK-12)</label>
                    <input :id="`a4-tf-${ch.id}`" v-model.number="recommendForm.remedy_timeframe_days" type="number" min="1" />
                    <label :for="`a4-veto-${ch.id}`">Veto window (days · CLK-11)</label>
                    <input :id="`a4-veto-${ch.id}`" v-model.number="recommendForm.veto_window_days" type="number" min="1" />
                    <button type="submit" :disabled="busy !== '' || !can.recommend">Recommend remedy</button>
                    <p v-if="!can.recommend" role="status">A remedy is recommended after a contradiction is found (Art. IV §5.3).</p>
                </form>
                <p v-if="error" role="alert">{{ error }}</p>
                <p v-if="notice" role="status">{{ notice }}</p>
            </div>
        </Card>

        <!-- 5 · The three paths -->
        <Stepper :steps="steps" />

        <div
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: var(--space-5)"
            role="group"
            aria-label="The three Art. IV §5 resolution paths"
        >
            <!-- Path A — Legislature amends or removes -->
            <Card as="section" aria-label="Path A">
                <div class="cluster" style="justify-content: space-between; margin-block-end: var(--space-2)">
                    <h3 style="margin-block: 0">Path A — Legislature modifies or removes</h3>
                    <StatusBadge :tone="pathBadge('A').tone" :icon="pathBadge('A').icon">
                        {{ pathBadge('A').text }}
                    </StatusBadge>
                </div>
                <p style="font-size: var(--text-sm)">
                    The legislature modifies or removes the law through the ordinary bill flow within
                    the judicial timeframe.
                </p>
                <p v-if="ch.bill_href"><a :href="ch.bill_href">Amendment bill in committee →</a></p>
                <!-- IO-3 · a member of the offending law's legislature opens Path 1 -->
                <p v-if="can.isLegislatureMember && can.proposeAmendment && ch.amendment_bill_new_href">
                    <a :href="ch.amendment_bill_new_href">Propose amendment bill →</a>
                </p>
                <p v-else-if="can.isLegislatureMember" role="status" class="a4-reason">
                    A remedial bill is proposed while the legislative window is open (Art. IV §5.3).
                </p>
                <p class="citation">
                    due within {{ remedy.timeframe_days }} days of the finding · {{ remedy.clk || 'CLK-12' }} ·
                    Art. IV §5 — opinions remain commentary on the law as edited
                </p>
            </Card>

            <!-- Path B — Supermajority override in the veto window -->
            <Card as="section" aria-label="Path B">
                <div class="cluster" style="justify-content: space-between; margin-block-end: var(--space-2)">
                    <h3 style="margin-block: 0">Path B — Supermajority override in the veto window</h3>
                    <StatusBadge :tone="pathBadge('B').tone" :icon="pathBadge('B').icon">
                        {{ pathBadge('B').text }}
                    </StatusBadge>
                </div>
                <div v-if="override.form_card" class="card card--inset">
                    <div class="cluster" style="justify-content: space-between; align-items: baseline">
                        <strong style="color: var(--gov-fg)">{{ override.form_card.name }}</strong>
                        <FormChip :form-id="override.form_card.id" :alias="override.form_card.alias" />
                    </div>
                    <p class="citation" style="margin-block-start: var(--space-1)">{{ override.form_card.citation }}</p>
                </div>

                <div style="margin-block-start: var(--space-3)">
                    <VoteTally
                        v-if="override.vote"
                        mode="unicameral"
                        threshold-class="supermajority"
                        :serving="override.vote.serving"
                        :required-yes="override.vote.requiredYes"
                        :tallies="override.vote.tallies"
                        :outcome="override.vote.outcome"
                    />
                    <ThresholdMeter
                        v-else
                        :value="override.yes"
                        :max="override.serving"
                        :threshold="override.required"
                        label="Override — votes in favor of all serving"
                    >
                        {{ override.yes }} of {{ override.serving }} serving members in favor
                        <template #note>
                            <span data-no-i18n
                                >needs {{ override.required }} of {{ override.serving }} ·
                                ceil({{ override.serving }} × 2/3) · Art. VII</span
                            >
                        </template>
                    </ThresholdMeter>
                </div>

                <p class="gloss">
                    Supermajority of all serving members — not just those present — recorded within the
                    veto window.
                </p>

                <!-- IO-3 · a member of the offending law's legislature opens the override -->
                <form
                    v-if="can.isLegislatureMember"
                    class="a4-control"
                    :aria-busy="busy === 'override'"
                    @submit.prevent="submitOverride"
                >
                    <label :for="`a4-dissent-${ch.id}`">Dissent (optional, public)</label>
                    <textarea :id="`a4-dissent-${ch.id}`" v-model="overrideForm.dissent_text" rows="2" maxlength="10000" />
                    <button type="submit" :disabled="busy !== '' || !can.override">Open override vote</button>
                    <p v-if="!can.override" role="status">
                        A supermajority override opens while the legislative window is open, within the veto
                        window (Art. IV §5.4).
                    </p>
                    <p v-if="error && busy === ''" role="alert">{{ error }}</p>
                </form>

                <Banner
                    v-if="resolution === 'overridden'"
                    tone="info"
                    role="status"
                    title="Judgement overruled"
                >
                    The law stands as written; the finding, the override vote, and every member’s
                    position are on the public record.
                    <span class="citation" data-no-i18n>F-LEG-035 · Art. IV §5</span>
                </Banner>
            </Card>

            <!-- Path C — Window closes, judiciary edits the law directly -->
            <Card as="section" aria-label="Path C">
                <div class="cluster" style="justify-content: space-between; margin-block-end: var(--space-2)">
                    <h3 style="margin-block: 0">Path C — Window closes, judiciary edits the law</h3>
                    <StatusBadge :tone="pathBadge('C').tone" :icon="pathBadge('C').icon">
                        {{ pathBadge('C').text }}
                    </StatusBadge>
                </div>
                <p style="font-size: var(--text-sm)">
                    If the window closes with neither amendment nor override, the judiciary applies its
                    remedy directly to the law’s text. Version history is preserved.
                </p>

                <LawDiff
                    v-if="diff"
                    :segments="diff.segments"
                    :label="`${ch.law?.name} — ${diff.applied ? 'as edited by the judiciary' : 'remedy preview'}`"
                />
                <div v-if="ch.judicial_remedy_form_card" class="card card--inset" style="margin-block-start: var(--space-2)">
                    <div class="cluster" style="justify-content: space-between; align-items: baseline">
                        <strong style="color: var(--gov-fg)">{{ ch.judicial_remedy_form_card.name }}</strong>
                        <FormChip :form-id="ch.judicial_remedy_form_card.id" :alias="ch.judicial_remedy_form_card.alias" />
                    </div>
                    <p class="citation" style="margin-block-start: var(--space-1)">{{ ch.judicial_remedy_form_card.citation }}</p>
                </div>
                <!-- IO-3 · a seated judge applies the remedy once both windows close -->
                <form
                    v-if="can.isSeatedJudge"
                    class="a4-control"
                    :aria-busy="busy === 'remedy'"
                    @submit.prevent="submitRemedy"
                >
                    <button type="submit" :disabled="busy !== '' || !can.remedy">Apply the remedy now</button>
                    <p v-if="!can.remedy" role="status">
                        Available once both the remedy timeframe and the veto window have closed; the CLK-11
                        sweep applies it automatically otherwise (Art. IV §5.5).
                    </p>
                    <p v-if="error && busy === ''" role="alert">{{ error }}</p>
                </form>

                <p class="citation" style="margin-block-start: var(--space-2)">
                    opinions remain commentary on the law as written or edited · Art. IV §5
                </p>

                <Banner
                    v-if="resolution === 'applied'"
                    tone="info"
                    role="status"
                    title="Remedy applied directly"
                >
                    {{ ch.law?.name }} is edited to the text above; a new law version is published with
                    the prior version retained in history.
                    <a v-if="diff?.history_href" :href="diff.history_href">
                        Version {{ diff.version_no }} (prior: {{ diff.prior_version_no }}) →
                    </a>
                    <span class="citation" data-no-i18n>F-JDG-006 · judicial_remedy · Art. IV §5</span>
                </Banner>
            </Card>
        </div>

        <!-- 6 · Enforcement -->
        <Banner tone="info" role="note" title="Executives enforce the outcome — whichever path resolves">
            Enforcement aligns to the final state of the law: amended, upheld by override, or edited by
            the court.
            <a v-if="ch.enforcement?.href" :href="ch.enforcement.href">Executive actions</a>
            <span class="citation" data-no-i18n>Art. IV §5 · WF-EXE-07</span>
        </Banner>
    </div>
</template>

<style scoped>
/* IO-3 outcome controls — 44px targets, kit tokens, theme-agnostic. */
.a4-controls { display: grid; gap: var(--space-4); margin-block-start: var(--space-4); border-block-start: 1px solid var(--border, #344054); padding-block-start: var(--space-4); }
.a4-control { display: grid; gap: 0.5rem; }
.a4-control h4 { margin-block: 0; color: var(--gov-fg); }
.a4-control label { font-size: var(--text-sm); color: var(--gov-fg); }
.a4-control textarea, .a4-control input[type='number'], .a4-control select { inline-size: 100%; max-inline-size: 42rem; font: inherit; min-block-size: 44px; }
.a4-control .a4-check { display: flex; align-items: center; gap: 0.5rem; }
.a4-control .a4-check input { min-block-size: auto; }
.a4-control button { min-block-size: 44px; inline-size: fit-content; font: inherit; }
.a4-reason { font-size: var(--text-sm); }
</style>
