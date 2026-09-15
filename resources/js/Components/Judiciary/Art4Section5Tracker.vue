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
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();

const STAGE_GLOSS = computed(() =>
    t(
        'c_institution_components.art4_section5_tracker.stage_gloss',
        'The finding lands on the legislature as a mandatory session priority — constitutional matters precede the general agenda (WF-LEG-05).',
    ),
);

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
        onError: (errs) => { error.value = errs.constitution || Object.values(errs)[0] || t('c_institution_components.art4_section5_tracker.action_refused', 'The action was refused. Please retry.'); },
        onSuccess: () => { notice.value = t('c_institution_components.art4_section5_tracker.recorded_notice', 'Recorded on the public register — the tracker reflects the new state.'); },
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
        { label: t('c_institution_components.art4_section5_tracker.step_finding_remedy', 'Finding + remedy'), icon: 'scale', state: 'done' },
        { label: t('c_institution_components.art4_section5_tracker.step_legislative_window', 'Legislative window'), icon: 'clock', state: resolved ? 'done' : 'active' },
        { label: t('c_institution_components.art4_section5_tracker.step_resolved', 'Resolved'), icon: 'check', state: resolved ? 'done' : 'pending' },
    ];
});

/* Per-path status badge: the engine's resolution decides, never the UI. */
function pathBadge(path) {
    const r = resolution.value;
    const resolved = { tone: 'success', icon: 'check', text: t('c_institution_components.art4_section5_tracker.badge_resolved', 'Resolved') };
    const open = { tone: 'info', icon: 'clock', text: t('c_institution_components.art4_section5_tracker.badge_open', 'Open') };
    const closed = { tone: 'neutral', icon: 'x', text: t('c_institution_components.art4_section5_tracker.badge_closed', 'Closed — resolved on another path') };
    if (path === 'A') {
        return r === 'amended' ? resolved : r === 'window_open' ? open : closed;
    }
    if (path === 'B') {
        return r === 'overridden' ? resolved : r === 'window_open' ? open : closed;
    }
    return r === 'applied'
        ? resolved
        : r === 'window_open'
          ? { tone: 'neutral', icon: 'clock', text: t('c_institution_components.art4_section5_tracker.badge_pending_window', 'Pending window close') }
          : closed;
}
</script>

<template>
    <!-- =================================================== EMPTY STATE === -->
    <Card v-if="!challenge" as="section" :title="t('c_institution_components.art4_section5_tracker.empty_title', 'No constitutional challenge is open in your jurisdictions')">
        <div class="cluster" style="margin-block-end: var(--space-3)">
            <StatusBadge tone="neutral" icon="info">{{ t('c_institution_components.art4_section5_tracker.no_open_challenge', 'No open challenge') }}</StatusBadge>
        </div>
        <p>
            {{ t('c_institution_components.art4_section5_tracker.empty_body', 'When a court issues a finding under Art. IV §5, this tracker shows the finding, the recommended remedy, both clocks (CLK-11 / CLK-12), and the three resolution paths live.') }}
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
            :title="t('c_institution_components.art4_section5_tracker.window_banner_title', 'Legislative window open — override closes {date}', { date: remedy.veto_closes_on })"
        >
            {{ t('c_institution_components.art4_section5_tracker.window_banner_body', 'The legislature has {days} days to modify or remove the law (CLK-12, due {due}) and {vetoDays} days to override ({clk}).', { days: remedy.timeframe_days, due: remedy.timeframe_due_on, vetoDays: remedy.veto_window_days, clk: remedy.veto_clk || 'CLK-11' }) }}
            <span class="citation" data-no-i18n>{{ remedy.tz || 'stored as UTC' }}</span>
        </Banner>

        <!-- 2 · Challenge summary + the F-IND-016 entry point -->
        <Card as="section" :title="t('c_institution_components.art4_section5_tracker.active_challenge', 'Active challenge')">
            <h2 style="margin-block: var(--space-1) var(--space-2)">{{ ch.name }}</h2>
            <p>
                <strong style="color: var(--gov-fg)">{{ t('c_institution_components.art4_section5_tracker.law_challenged', 'Law challenged:') }}</strong>
                <a v-if="ch.law?.href" :href="ch.law.href">{{ ch.law?.name }}</a>
                <template v-else>{{ ch.law?.name }}</template>
            </p>
            <p>
                <strong style="color: var(--gov-fg)">{{ t('c_institution_components.art4_section5_tracker.filed', 'Filed:') }}</strong> {{ ch.filed_at }}
                {{ t('c_institution_components.art4_section5_tracker.filed_by', 'by {who} — any inhabitant may file; no standing gatekeeper beyond jurisdictional association.', { who: ch.filed_by_label }) }}
            </p>
            <p>
                <strong style="color: var(--gov-fg)">{{ t('c_institution_components.art4_section5_tracker.heard_by', 'Heard by:') }}</strong>
                <template v-if="ch.is_major">
                    {{ t('c_institution_components.art4_section5_tracker.full_court', 'the full court — all {n} judges · CLK-16, {judge} writing.', { n: ch.full_court_size, judge: ch.writing_judge?.name }) }}
                    <HardenedChip />
                </template>
                <template v-else>
                    {{ t('c_institution_components.art4_section5_tracker.panel_court', 'a severity-scaled panel of the {court}, {judge} writing.', { court: ch.court?.name, judge: ch.writing_judge?.name }) }}
                </template>
            </p>
            <p class="citation">{{ t('c_institution_components.art4_section5_tracker.right_to_challenge', 'Right to challenge · Art. IV §5') }}</p>
            <div v-if="fileForm" class="stack" style="gap: var(--space-3); margin-block-start: var(--space-3)">
                <FormCard :form="fileForm">
                    <slot name="file-fields" />
                </FormCard>
            </div>
        </Card>

        <!-- 3 · Challenge ESM state strip -->
        <Card as="section" inset>
            <span class="eyebrow">{{ t('c_institution_components.art4_section5_tracker.challenge_esm', 'Constitutional Challenge state machine') }}</span>
            <div style="margin-block-start: var(--space-2)">
                <StateStrip :states="machine" :current="ch.state" />
            </div>
        </Card>

        <!-- 4 · Finding & remedy — reference cards (the court's record, not forms here) -->
        <Card as="section" :title="t('c_institution_components.art4_section5_tracker.finding_remedy_title', 'Finding & recommended remedy')">
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
                        “{{ remedy.text }}.” {{ t('c_institution_components.art4_section5_tracker.remedy_timeframe', 'Timeframe: {days} days ({clk}) · veto window: {vetoDays} days ({vetoClk}).', { days: remedy.timeframe_days, clk: remedy.clk || 'CLK-12', vetoDays: remedy.veto_window_days, vetoClk: remedy.veto_clk || 'CLK-11' }) }}
                    </p>
                </div>
            </div>
            <p class="gloss" style="margin-block-start: var(--space-3)">{{ STAGE_GLOSS }}</p>

            <!-- IO-3 · a seated judge records the finding, then the remedy -->
            <div v-if="can.isSeatedJudge" class="a4-controls">
                <form class="a4-control" :aria-busy="busy === 'finding'" @submit.prevent="submitFinding">
                    <h4>{{ t('c_institution_components.art4_section5_tracker.record_finding_h', 'Record the constitutional finding') }}</h4>
                    <label :for="`a4-finds-${ch.id}`">{{ t('c_institution_components.art4_section5_tracker.determination', 'Determination') }}</label>
                    <select :id="`a4-finds-${ch.id}`" v-model="findingForm.finds_contradiction">
                        <option value="true">{{ t('c_institution_components.art4_section5_tracker.opt_contradiction_found', 'A contradiction is found (opens the remedy step)') }}</option>
                        <option value="false">{{ t('c_institution_components.art4_section5_tracker.opt_no_contradiction', 'No contradiction (dismiss the challenge)') }}</option>
                    </select>
                    <label :for="`a4-opinion-${ch.id}`">{{ t('c_institution_components.art4_section5_tracker.opinion', 'Opinion') }}</label>
                    <textarea :id="`a4-opinion-${ch.id}`" v-model="findingForm.opinion_text" rows="3" maxlength="10000" />
                    <label class="a4-check"><input v-model="findingForm.full_court" type="checkbox" /> {{ t('c_institution_components.art4_section5_tracker.heard_full_court', 'Heard by the full court') }}</label>
                    <button type="submit" :disabled="busy !== '' || !can.finding">{{ t('c_institution_components.art4_section5_tracker.record_finding_btn', 'Record finding') }}</button>
                    <p v-if="!can.finding" role="status">{{ t('c_institution_components.art4_section5_tracker.finding_gate', 'A finding is recorded while the challenge is under review (Art. IV §5.2).') }}</p>
                </form>

                <form class="a4-control" :aria-busy="busy === 'recommend'" @submit.prevent="submitRecommend">
                    <h4>{{ t('c_institution_components.art4_section5_tracker.recommend_h', 'Recommend the remedy and set the windows') }}</h4>
                    <label :for="`a4-kind-${ch.id}`">{{ t('c_institution_components.art4_section5_tracker.remedy', 'Remedy') }}</label>
                    <select :id="`a4-kind-${ch.id}`" v-model="recommendForm.remedy_kind">
                        <option value="modify">{{ t('c_institution_components.art4_section5_tracker.opt_modify', 'Modify the law (provide replacement text)') }}</option>
                        <option value="remove">{{ t('c_institution_components.art4_section5_tracker.opt_remove', 'Remove the law (repeal)') }}</option>
                    </select>
                    <template v-if="recommendForm.remedy_kind === 'modify'">
                        <label :for="`a4-text-${ch.id}`">{{ t('c_institution_components.art4_section5_tracker.replacement_text', 'Replacement text') }}</label>
                        <textarea :id="`a4-text-${ch.id}`" v-model="recommendForm.recommended_text" rows="3" maxlength="20000" />
                    </template>
                    <label :for="`a4-rationale-${ch.id}`">{{ t('c_institution_components.art4_section5_tracker.rationale', 'Why this makes the law non-contradictory') }}</label>
                    <textarea :id="`a4-rationale-${ch.id}`" v-model="recommendForm.rationale_text" rows="2" maxlength="10000" />
                    <label :for="`a4-tf-${ch.id}`">{{ t('c_institution_components.art4_section5_tracker.remedy_timeframe_label', 'Remedy timeframe (days · CLK-12)') }}</label>
                    <input :id="`a4-tf-${ch.id}`" v-model.number="recommendForm.remedy_timeframe_days" type="number" min="1" />
                    <label :for="`a4-veto-${ch.id}`">{{ t('c_institution_components.art4_section5_tracker.veto_window_label', 'Veto window (days · CLK-11)') }}</label>
                    <input :id="`a4-veto-${ch.id}`" v-model.number="recommendForm.veto_window_days" type="number" min="1" />
                    <button type="submit" :disabled="busy !== '' || !can.recommend">{{ t('c_institution_components.art4_section5_tracker.recommend_btn', 'Recommend remedy') }}</button>
                    <p v-if="!can.recommend" role="status">{{ t('c_institution_components.art4_section5_tracker.recommend_gate', 'A remedy is recommended after a contradiction is found (Art. IV §5.3).') }}</p>
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
            :aria-label="t('c_institution_components.art4_section5_tracker.paths_group_aria', 'The three Art. IV §5 resolution paths')"
        >
            <!-- Path A — Legislature amends or removes -->
            <Card as="section" :aria-label="t('c_institution_components.art4_section5_tracker.path_a_aria', 'Path A')">
                <div class="cluster" style="justify-content: space-between; margin-block-end: var(--space-2)">
                    <h3 style="margin-block: 0">{{ t('c_institution_components.art4_section5_tracker.path_a_h', 'Path A — Legislature modifies or removes') }}</h3>
                    <StatusBadge :tone="pathBadge('A').tone" :icon="pathBadge('A').icon">
                        {{ pathBadge('A').text }}
                    </StatusBadge>
                </div>
                <p style="font-size: var(--text-sm)">
                    {{ t('c_institution_components.art4_section5_tracker.path_a_body', 'The legislature modifies or removes the law through the ordinary bill flow within the judicial timeframe.') }}
                </p>
                <p v-if="ch.bill_href"><a :href="ch.bill_href">{{ t('c_institution_components.art4_section5_tracker.amendment_bill_committee', 'Amendment bill in committee →') }}</a></p>
                <!-- IO-3 · a member of the offending law's legislature opens Path 1 -->
                <p v-if="can.isLegislatureMember && can.proposeAmendment && ch.amendment_bill_new_href">
                    <a :href="ch.amendment_bill_new_href">{{ t('c_institution_components.art4_section5_tracker.propose_amendment_bill', 'Propose amendment bill →') }}</a>
                </p>
                <p v-else-if="can.isLegislatureMember" role="status" class="a4-reason">
                    {{ t('c_institution_components.art4_section5_tracker.remedial_bill_gate', 'A remedial bill is proposed while the legislative window is open (Art. IV §5.3).') }}
                </p>
                <p class="citation">
                    {{ t('c_institution_components.art4_section5_tracker.path_a_cite', 'due within {days} days of the finding · {clk} · Art. IV §5 — opinions remain commentary on the law as edited', { days: remedy.timeframe_days, clk: remedy.clk || 'CLK-12' }) }}
                </p>
            </Card>

            <!-- Path B — Supermajority override in the veto window -->
            <Card as="section" :aria-label="t('c_institution_components.art4_section5_tracker.path_b_aria', 'Path B')">
                <div class="cluster" style="justify-content: space-between; margin-block-end: var(--space-2)">
                    <h3 style="margin-block: 0">{{ t('c_institution_components.art4_section5_tracker.path_b_h', 'Path B — Supermajority override in the veto window') }}</h3>
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
                        :label="t('c_institution_components.art4_section5_tracker.override_meter_label', 'Override — votes in favor of all serving')"
                    >
                        {{ t('c_institution_components.art4_section5_tracker.override_in_favor', '{yes} of {serving} serving members in favor', { yes: override.yes, serving: override.serving }) }}
                        <template #note>
                            <span data-no-i18n
                                >needs {{ override.required }} of {{ override.serving }} ·
                                ceil({{ override.serving }} × 2/3) · Art. VII</span
                            >
                        </template>
                    </ThresholdMeter>
                </div>

                <p class="gloss">
                    {{ t('c_institution_components.art4_section5_tracker.override_gloss', 'Supermajority of all serving members — not just those present — recorded within the veto window.') }}
                </p>

                <!-- IO-3 · a member of the offending law's legislature opens the override -->
                <form
                    v-if="can.isLegislatureMember"
                    class="a4-control"
                    :aria-busy="busy === 'override'"
                    @submit.prevent="submitOverride"
                >
                    <label :for="`a4-dissent-${ch.id}`">{{ t('c_institution_components.art4_section5_tracker.dissent_label', 'Dissent (optional, public)') }}</label>
                    <textarea :id="`a4-dissent-${ch.id}`" v-model="overrideForm.dissent_text" rows="2" maxlength="10000" />
                    <button type="submit" :disabled="busy !== '' || !can.override">{{ t('c_institution_components.art4_section5_tracker.open_override_btn', 'Open override vote') }}</button>
                    <p v-if="!can.override" role="status">
                        {{ t('c_institution_components.art4_section5_tracker.override_gate', 'A supermajority override opens while the legislative window is open, within the veto window (Art. IV §5.4).') }}
                    </p>
                    <p v-if="error && busy === ''" role="alert">{{ error }}</p>
                </form>

                <Banner
                    v-if="resolution === 'overridden'"
                    tone="info"
                    role="status"
                    :title="t('c_institution_components.art4_section5_tracker.overruled_title', 'Judgement overruled')"
                >
                    {{ t('c_institution_components.art4_section5_tracker.overruled_body', 'The law stands as written; the finding, the override vote, and every member’s position are on the public record.') }}
                    <span class="citation" data-no-i18n>F-LEG-035 · Art. IV §5</span>
                </Banner>
            </Card>

            <!-- Path C — Window closes, judiciary edits the law directly -->
            <Card as="section" :aria-label="t('c_institution_components.art4_section5_tracker.path_c_aria', 'Path C')">
                <div class="cluster" style="justify-content: space-between; margin-block-end: var(--space-2)">
                    <h3 style="margin-block: 0">{{ t('c_institution_components.art4_section5_tracker.path_c_h', 'Path C — Window closes, judiciary edits the law') }}</h3>
                    <StatusBadge :tone="pathBadge('C').tone" :icon="pathBadge('C').icon">
                        {{ pathBadge('C').text }}
                    </StatusBadge>
                </div>
                <p style="font-size: var(--text-sm)">
                    {{ t('c_institution_components.art4_section5_tracker.path_c_body', 'If the window closes with neither amendment nor override, the judiciary applies its remedy directly to the law’s text. Version history is preserved.') }}
                </p>

                <LawDiff
                    v-if="diff"
                    :segments="diff.segments"
                    :label="t('c_institution_components.art4_section5_tracker.law_diff_label', '{name} — {state}', { name: ch.law?.name, state: diff.applied ? t('c_institution_components.art4_section5_tracker.diff_as_edited', 'as edited by the judiciary') : t('c_institution_components.art4_section5_tracker.diff_remedy_preview', 'remedy preview') })"
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
                    <button type="submit" :disabled="busy !== '' || !can.remedy">{{ t('c_institution_components.art4_section5_tracker.apply_remedy_btn', 'Apply the remedy now') }}</button>
                    <p v-if="!can.remedy" role="status">
                        {{ t('c_institution_components.art4_section5_tracker.remedy_gate', 'Available once both the remedy timeframe and the veto window have closed; the CLK-11 sweep applies it automatically otherwise (Art. IV §5.5).') }}
                    </p>
                    <p v-if="error && busy === ''" role="alert">{{ error }}</p>
                </form>

                <p class="citation" style="margin-block-start: var(--space-2)">
                    {{ t('c_institution_components.art4_section5_tracker.path_c_cite', 'opinions remain commentary on the law as written or edited · Art. IV §5') }}
                </p>

                <Banner
                    v-if="resolution === 'applied'"
                    tone="info"
                    role="status"
                    :title="t('c_institution_components.art4_section5_tracker.applied_title', 'Remedy applied directly')"
                >
                    {{ t('c_institution_components.art4_section5_tracker.applied_body', '{name} is edited to the text above; a new law version is published with the prior version retained in history.', { name: ch.law?.name }) }}
                    <a v-if="diff?.history_href" :href="diff.history_href">
                        {{ t('c_institution_components.art4_section5_tracker.version_link', 'Version {n} (prior: {prior}) →', { n: diff.version_no, prior: diff.prior_version_no }) }}
                    </a>
                    <span class="citation" data-no-i18n>F-JDG-006 · judicial_remedy · Art. IV §5</span>
                </Banner>
            </Card>
        </div>

        <!-- 6 · Enforcement -->
        <Banner tone="info" role="note" :title="t('c_institution_components.art4_section5_tracker.enforce_title', 'Executives enforce the outcome — whichever path resolves')">
            {{ t('c_institution_components.art4_section5_tracker.enforce_body', 'Enforcement aligns to the final state of the law: amended, upheld by override, or edited by the court.') }}
            <a v-if="ch.enforcement?.href" :href="ch.enforcement.href">{{ t('c_institution_components.art4_section5_tracker.executive_actions', 'Executive actions') }}</a>
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
