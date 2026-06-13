<script setup>
/**
 * Judiciary/CaseLifecycle — the 10-stage case walkthrough (FE-E1;
 * PHASE_E_DESIGN_frontend.md §A.2; case-detail.html centerpiece). Promotes
 * the mockup's stages[]/STAGE_STATE[] machinery into a component: the dual
 * rendering of the Case ESM `state-strip` (the live resting state) AND the
 * 10-stage ordinal `lifecycle` track.
 *
 * CONSTITUTIONAL POSTURE — append-only, never POSTs: the live record
 * renders `case.current_stage`; the court advances the record through
 * F-JDG-* on the page's own endpoints (R-19/R-20 gated), never a client
 * toggle. `interactive` is OFF in product (the playable Back/Advance is a
 * dev/demo simulation, like the CoDetScale explorer in Phase D) and carries
 * the verbatim banner that stage changes are simulation only.
 *
 * Per-stage `content_blocks` are server-rendered payloads the PAGE supplies
 * through the named slot `stage-{index}` (PanelTable for stage 3, evidence /
 * motions DataTables for 4–5, the jury Banner for 6, locked chambers cards
 * for 8, the double-jeopardy Banner for 9, the opinion FormCard for 10).
 *
 * Classes: .lifecycle/--done/--current, .state-strip, .banner--demo,
 * .card--inset — all already ported, no new CSS.
 */
import { computed, ref, watchEffect } from 'vue';
import Banner from '@/Components/Ui/Banner.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';

const props = defineProps({
    /**
     * cases row (server-shaped):
     * { id, docket_no, title, kind, severity, court:{name},
     *   double_jeopardy:bool, jury_entitled:bool,
     *   current_stage:1..10, current_state }
     */
    case: { type: Object, required: true },
    /** Case ESM states (config/cga/state_machines.php 'case') — PHP-owned. */
    machine: { type: Array, required: true },
    /** [{ index, title }] — server-authored stage labels (ordinal track). */
    stages: { type: Array, required: true },
    /** STAGE_STATE: 1-based stage index → ESM state (server-authored). */
    stageStateMap: { type: Array, required: true },
    /** The playable Back/Advance walkthrough — dev/demo affordance, OFF in product. */
    interactive: { type: Boolean, default: false },
});

const SIM_BANNER =
    'Stage changes below are simulation only — the real case record is append-only and ' +
    'advances when the court acts.';

/* The live record stage drives display; interactive mode previews a cursor
   over the SAME stage list without ever touching the record. */
const cursor = ref(props.case.current_stage);
watchEffect(() => {
    cursor.value = props.case.current_stage;
});

const activeStage = computed(() => (props.interactive ? cursor.value : props.case.current_stage));

/* The ESM state shown on the strip: the live record state in product; the
   cursor's mapped state in the interactive simulation. */
const stripCurrent = computed(() =>
    props.interactive ? props.stageStateMap[cursor.value - 1] ?? props.case.current_state : props.case.current_state,
);

function stageState(index) {
    if (index < activeStage.value) return 'done';
    if (index === activeStage.value) return 'current';
    return 'pending';
}

function back() {
    if (cursor.value > 1) cursor.value -= 1;
}
function advance() {
    if (cursor.value < props.stages.length) cursor.value += 1;
}
</script>

<template>
    <div class="stack" style="gap: var(--space-4)">
        <Banner v-if="interactive" tone="demo" role="note" title="Playable walkthrough">
            {{ SIM_BANNER }}
        </Banner>

        <div class="card card--inset" aria-label="Case state machine">
            <span class="eyebrow">Case state machine</span>
            <div style="margin-block-start: var(--space-2)">
                <StateStrip :states="machine" :current="stripCurrent" />
            </div>
        </div>

        <div>
            <ol class="lifecycle">
                <li
                    v-for="stage in stages"
                    :key="stage.index"
                    class="lifecycle-stage"
                    :class="{
                        'lifecycle-stage--done': stageState(stage.index) === 'done',
                        'lifecycle-stage--current': stageState(stage.index) === 'current',
                    }"
                    :aria-current="stageState(stage.index) === 'current' ? 'step' : undefined"
                >
                    {{ stage.index }} · {{ stage.title }}
                </li>
            </ol>

            <div v-if="interactive" class="cluster" style="margin-block-start: var(--space-4)">
                <button type="button" class="btn btn--secondary" :disabled="cursor <= 1" @click="back">Back</button>
                <button type="button" class="btn btn--primary" :disabled="cursor >= stages.length" @click="advance">
                    Advance
                </button>
                <span class="gloss">Back and Advance move through the sequence — simulation only.</span>
            </div>
        </div>

        <!-- Per-stage server payloads: the page supplies each via #stage-{index}.
             Only the active stage's panel renders (the live record context). -->
        <div aria-live="polite">
            <slot :name="`stage-${activeStage}`" :stage="activeStage" :case="case" />
        </div>
    </div>
</template>
