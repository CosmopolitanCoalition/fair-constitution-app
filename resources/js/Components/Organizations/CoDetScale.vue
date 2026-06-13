<script>
/**
 * Org/CoDetScale — the co-determination scale visual (FE-D1;
 * PHASE_D_DESIGN_frontend.md §A.2; port of
 * mockups/organizations/co-determination.html lines 27–58 +
 * workerSeats()/nextStep()).
 *
 * CONSTITUTIONAL POSTURE SPLIT — the static render shows ONLY server
 * numbers: `workerSeats` is THE ENGINE'S number (boards.worker_seats,
 * written only by CoDeterminationService), `nextStepAt` is the server
 * projection, and `thresholds` are the server-resolved
 * worker_rep_min/parity_employees (CLK-13/14 are AMENDABLE — the
 * constants 100/2000 are NEVER hardcoded client-side; everything below
 * reads props.thresholds).
 *
 * The interactive explorer is the ONE Phase D component where client
 * arithmetic is permitted, because it is explicitly an explorer of a
 * published formula, never a record: it recomputes locally with the SAME
 * server-supplied thresholds and labels everything moved off the live
 * value "projection — the engine recomputes on real headcount change ·
 * WF-ORG-04". The live readout ignores the slider entirely.
 *
 * The formula is exported for unit pinning (99→0; 100→1 — the max(1,…)
 * floor; 740/9→3; 2000/9→9 — the min(owner,…) cap) and mirrors
 * App\Services\CoDeterminationService::requiredWorkerSeats().
 */
export function workerSeatsFromThresholds(workers, ownerSeats, thresholds) {
    const { min, parity } = thresholds;
    if (workers < min) return 0;
    return Math.max(1, Math.min(ownerSeats, Math.round(((workers - min) / (parity - min)) * ownerSeats)));
}

/** Smallest headcount at which the seat count increases past `seats`
 *  (the mockup's nextStep() — explorer-only projection). */
export function nextStepFromThresholds(seats, ownerSeats, thresholds) {
    const { min, parity } = thresholds;
    if (seats >= ownerSeats) return null;
    return Math.min(Math.ceil(((seats + 0.5) / ownerSeats) * (parity - min) + min), parity);
}
</script>

<script setup>
import { computed, ref, useId, watch } from 'vue';
import Btn from '@/Components/Ui/Btn.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    /** Live headcount: COUNT(org_workers WHERE ended_at IS NULL) — server. */
    workers: { type: Number, required: true },
    /** boards.owner_seats. */
    ownerSeats: { type: Number, required: true },
    /** boards.worker_seats — THE ENGINE'S NUMBER, never recomputed here. */
    workerSeats: { type: Number, required: true },
    /** { min, parity } — server-resolved worker_rep_min/parity_employees. */
    thresholds: { type: Object, required: true },
    /** Server projection: smallest headcount adding a seat. */
    nextStepAt: { type: Number, default: null },
    /** Renders the range-slider EXPLORER. */
    interactive: { type: Boolean, default: false },
    entityLabel: { type: String, default: null },
});

const fmt = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString());

/* ---------------------------------------------------------- the track --- */
/* Track maximum = parity × 1.2 (the mockup's 2,400 for parity 2,000). */
const trackMax = computed(() => props.thresholds.parity * 1.2);
const pctOf = (n) => Math.min(100, Math.round((n / trackMax.value) * 100));
const minMarkPct = computed(() => (props.thresholds.min / trackMax.value) * 100);
const parityMarkPct = computed(() => (props.thresholds.parity / trackMax.value) * 100);

/* ------------------------------------------------------------ explorer -- */
const sliderId = useId();
const readoutId = useId();
const slider = ref(props.workers);
watch(() => props.workers, (w) => { slider.value = w; });

const exploring = computed(() => props.interactive && Number(slider.value) !== props.workers);

/* Displayed (meter + readout) numbers: the live server numbers, or the
   explorer projection when the slider has moved off the live value. */
const shownWorkers = computed(() => (props.interactive ? Number(slider.value) : props.workers));
const shownSeats = computed(() =>
    exploring.value
        ? workerSeatsFromThresholds(shownWorkers.value, props.ownerSeats, props.thresholds)
        : props.workerSeats,
);
const shownNextStep = computed(() =>
    exploring.value
        ? nextStepFromThresholds(shownSeats.value, props.ownerSeats, props.thresholds)
        : props.nextStepAt,
);

const fillPct = computed(() => pctOf(shownWorkers.value));
const atParity = computed(() => shownWorkers.value >= props.thresholds.parity);

/* Status badge grammar verbatim from co-determination.html lines 196–199
   (threshold values live from props — never literals). THE LIVE BADGE
   IGNORES SLIDER STATE (§A.2 pin): it always reflects the server
   headcount; the explorer's moved state is conveyed by the projection
   flag + recomputed stats, never by this badge. */
const badge = computed(() => {
    const w = props.workers;
    if (w < props.thresholds.min) {
        return { tone: 'neutral', icon: null, text: `no worker seats yet — first seat at ${fmt(props.thresholds.min)} · CLK-13` };
    }
    if (w >= props.thresholds.parity) {
        return { tone: 'success', icon: 'users', text: 'parity — worker seats equal owner seats · CLK-14' };
    }
    return { tone: 'info', icon: 'users', text: 'scaling between CLK-13 and CLK-14' };
});

/* The receipt formula block — substituted live numbers, data-no-i18n. */
const formulaGeneric = computed(
    () =>
        `worker_seats = max(1, round((W − ${fmt(props.thresholds.min)}) ÷ ` +
        `(${fmt(props.thresholds.parity)} − ${fmt(props.thresholds.min)}) × owner_seats))   for W ≥ ${fmt(props.thresholds.min)}`,
);
const formulaSubstituted = computed(() => {
    const w = shownWorkers.value;
    const span = props.thresholds.parity - props.thresholds.min;
    const result = w < props.thresholds.min ? '0 (below CLK-13)' : shownSeats.value;
    return `             = max(1, round((${fmt(w)} − ${fmt(props.thresholds.min)}) ÷ ${fmt(span)} × ${props.ownerSeats})) = ${result}`;
});

function resetToLive() {
    slider.value = props.workers;
}
</script>

<template>
    <div class="stack" style="gap: var(--space-3)">
        <p v-if="entityLabel" class="cc-small" style="margin: 0">
            <strong style="color: var(--gov-fg)">{{ entityLabel }}</strong> —
            {{ fmt(workers) }} workers against {{ ownerSeats }} owner-side seats.
        </p>

        <!-- explorer slider -->
        <div v-if="interactive" class="field" style="margin: 0">
            <label class="field-label" :for="sliderId">Worker headcount (explorer)</label>
            <input
                :id="sliderId"
                v-model.number="slider"
                class="range-input"
                type="range"
                min="0"
                :max="trackMax"
                step="10"
                :aria-describedby="readoutId"
            />
        </div>

        <!-- the scaling meter -->
        <div class="meter-block">
            <div
                class="meter meter--lg"
                role="meter"
                :aria-valuemin="0"
                :aria-valuemax="trackMax"
                :aria-valuenow="shownWorkers"
                :aria-label="`Worker headcount on the co-determination scale${entityLabel ? ` — ${entityLabel}` : ''}`"
            >
                <span
                    class="meter-fill"
                    :class="{ 'meter-fill--met': atParity }"
                    :style="{ 'inline-size': `${fillPct}%` }"
                ></span>
                <span class="meter-threshold" :style="{ 'inset-inline-start': `${minMarkPct}%` }" title="CLK-13 first worker seat"></span>
                <span class="meter-threshold" :style="{ 'inset-inline-start': `${parityMarkPct}%` }" title="CLK-14 parity"></span>
            </div>
            <div class="meter-caption">
                <span>0</span>
                <span>{{ fmt(thresholds.min) }} · first worker seat · CLK-13</span>
                <span>{{ fmt(thresholds.parity) }} · parity · CLK-14</span>
            </div>
        </div>

        <!-- readout -->
        <div :id="readoutId" class="cluster" style="gap: var(--space-6)" aria-live="polite">
            <Stat :value="fmt(shownWorkers)" label="workers (R-25)" />
            <Stat :value="shownSeats" label="worker-elected seats (R-27)" accent />
            <Stat :value="ownerSeats" label="owner-elected seats (R-26)" />
            <div>
                <StatusBadge :tone="badge.tone" :icon="badge.icon">{{ badge.text }}</StatusBadge>
                <span
                    v-if="shownNextStep !== null && shownWorkers >= thresholds.min"
                    class="citation"
                    style="display: block; margin-block-start: var(--space-1)"
                >next seat at {{ fmt(shownNextStep) }} workers (projection)</span>
            </div>
        </div>

        <!-- projection flag — everything moved off the live value -->
        <div v-if="exploring" class="cluster" role="status">
            <StatusBadge tone="warning" icon="sliders">
                projection — the engine recomputes on real headcount change · WF-ORG-04
            </StatusBadge>
            <Btn variant="secondary" size="sm" icon="refresh-cw" @click="resetToLive">
                Reset to live ({{ fmt(workers) }} workers · {{ workerSeats }} seats)
            </Btn>
        </div>

        <!-- the published formula, substituted -->
        <div class="receipt" data-no-i18n>
            {{ formulaGeneric }}<br />
            {{ formulaSubstituted }}
        </div>
        <p class="citation" style="margin: 0">
            Scales uniformly between the first seat ({{ fmt(thresholds.min) }}) and parity
            ({{ fmt(thresholds.parity) }}) · Art. III §6 · CLK-13 / CLK-14
        </p>
    </div>
</template>
