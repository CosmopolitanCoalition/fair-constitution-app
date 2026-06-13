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
 * CONSTITUTIONAL POSTURE — pure renderer, never decides the path: every
 * threshold, the override `required` count, the CLK-11/CLK-12 due dates,
 * and the `applied` boolean are ENGINE snapshots from constitutional_
 * challenges / chamber_votes / clock_timers.override_value / the
 * judicial_remedy LawVersion. The mockup's data-sim-* buttons (simulate
 * vote / window close / reset) are demo affordances and DO NOT ship — in
 * product Path B advances through real F-LEG-035 casts on the Phase C vote
 * endpoints, and Path C fires when CLK-11 expires (the engine writes the
 * LawVersion, the page re-renders it). Feed `override.required=99` and it
 * honestly displays 99 (the VoteTally pure-renderer discipline).
 *
 * Classes: all already ported — no new CSS.
 */
import { computed } from 'vue';
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
});

const STAGE_GLOSS =
    'The finding lands on the legislature as a mandatory session priority — constitutional ' +
    'matters precede the general agenda (WF-LEG-05).';

const ch = computed(() => props.challenge);
const remedy = computed(() => ch.value?.remedy ?? {});
const override = computed(() => ch.value?.override ?? {});
const diff = computed(() => ch.value?.remedy_diff ?? null);
const resolution = computed(() => ch.value?.resolution ?? 'window_open');

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
