<script setup>
import { computed, ref, watch } from 'vue'
import ProgressStatusBadge from './ProgressStatusBadge.vue'
import JurisdictionCountsGrid from './JurisdictionCountsGrid.vue'
// P.1.2: PhaseSummary removed — redundant with the stacked bars.
// import PhaseSummary from './PhaseSummary.vue'
import CurrentJurisdictionCard from './CurrentJurisdictionCard.vue'
import StackedProgressBars from './StackedProgressBars.vue'
import EventToasts from './EventToasts.vue'
import LogTailPanel from './LogTailPanel.vue'

const props = defineProps({
    lifecycle:      { type: String,  required: true },
    running:        { type: Object,  default: null },
    done:           { type: Object,  default: null },
    failed:         { type: Object,  default: null },
    progress:       { type: Object,  default: null },
    current:        { type: Object,  default: null },
    bars:           { type: Object,  default: null },   // Phase P.1 stacked-bars state
    events:         { type: Array,   default: () => [] },   // Phase P.3 structured events
    // Non-null when ETL is paused on a per-country error and awaiting an
    // operator decision (skip / retry / abort). Shape:
    //   { country, adm_level, phase, error_class, error_message, traceback }
    errorPause:     { type: Object,  default: null },
    counts:         { type: Object,  default: () => ({ adm0: 0, adm1: 0, adm2: 0, total: 0, total_with_pop: 0, total_sum_pop: 0, by_level: [] }) },
    logTail:        { type: Array,   default: () => [] },
    includeDebug:   { type: Boolean, default: false },
    pendingControl: { type: Object,  default: () => ({ halt: false, pause: false, resume: false }) },
})

const emit = defineEmits(['update:includeDebug', 'control'])

function sendControl(action) {
    emit('control', action)
}

const isPaused = computed(() => Boolean(props.running?.paused))
// P.1.2: pause-aware timestamp the supervisor stamps into running.json
// when SIGSTOPping the ETL. Used by the badge to freeze its session timer.
const pausedAt = computed(() => props.running?.paused_at || null)
const haltPending   = computed(() => Boolean(props.pendingControl?.halt))
const pausePending  = computed(() => Boolean(props.pendingControl?.pause))
const resumePending = computed(() => Boolean(props.pendingControl?.resume))

// Error-pause flow: when paused_on_error.json exists the ETL is waiting on the
// operator's choice. Disable the buttons after the first click so a double-tap
// can't write two resolution files in the 0.5s polling window.
const errorActionPending = ref(null)   // 'skip' | 'retry' | 'abort' | null

function sendErrorResolution(action) {
    errorActionPending.value = action
    emit('control', `error_${action}`)
}

// Once the ETL acknowledges (paused_on_error.json removed → errorPause prop
// flips to null), re-enable the buttons for the next time.
watch(
    () => props.errorPause,
    (curr) => { if (!curr) errorActionPending.value = null },
)

// Map error.phase to a friendly label.
const errorPhaseLabel = computed(() => {
    const p = props.errorPause?.phase
    if (!p) return ''
    return ({ geoboundaries: 'Boundaries', worldpop: 'Population' }[p]) ?? p
})

// Truncate the traceback for the inline display; full text is in the title attr.
const errorMessageShort = computed(() => {
    const m = props.errorPause?.error_message || ''
    return m.length > 240 ? m.slice(0, 240) + '…' : m
})

const summary = computed(() => {
    const p = props.progress
    if (!p) return null
    const gb = p.geoboundaries || {}
    const wp = p.worldpop || {}
    return {
        phase1_countries_done: Array.isArray(gb.countries_done) ? gb.countries_done.length : 0,
        phase2_countries_done: Array.isArray(wp.countries_done) ? wp.countries_done.length : 0,
        phase1_in_progress:    gb.in_progress_country || null,
        phase2_in_progress:    wp.in_progress_country || null,
    }
})

const startedAt = computed(() => props.running?.started_at || null)
const isRunning = computed(() => props.lifecycle === 'running')
const stoppedOnException = computed(() => Boolean(props.failed?.stopped_on_exception))
</script>

<template>
    <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4 gap-3">
            <h2 class="text-white font-semibold">3. Live Progress</h2>
            <div class="flex items-center gap-2">
                <template v-if="isRunning">
                    <button
                        v-if="!isPaused"
                        type="button"
                        @click="sendControl('pause')"
                        :disabled="pausePending"
                        class="text-xs px-2 py-1 rounded border bg-amber-900/40 border-amber-700 text-amber-200 hover:bg-amber-900/70 disabled:opacity-50"
                        title="Pause the ETL subprocess (SIGSTOP — DB connections stay open)"
                    >
                        {{ pausePending ? 'Pausing…' : 'Pause' }}
                    </button>
                    <button
                        v-else
                        type="button"
                        @click="sendControl('resume')"
                        :disabled="resumePending"
                        class="text-xs px-2 py-1 rounded border bg-emerald-900/40 border-emerald-700 text-emerald-200 hover:bg-emerald-900/70 disabled:opacity-50"
                        title="Resume the paused ETL subprocess (SIGCONT)"
                    >
                        {{ resumePending ? 'Resuming…' : 'Resume' }}
                    </button>
                    <button
                        type="button"
                        @click="sendControl('halt')"
                        :disabled="haltPending"
                        class="text-xs px-2 py-1 rounded border bg-red-900/40 border-red-700 text-red-200 hover:bg-red-900/70 disabled:opacity-50"
                        title="Stop the run (SIGTERM)"
                    >
                        {{ haltPending ? 'Halting…' : 'Halt' }}
                    </button>
                </template>
                <ProgressStatusBadge :lifecycle="lifecycle" :started-at="startedAt" :paused="isPaused" :paused-at="pausedAt" />
            </div>
        </div>

        <!-- Error-pause card: ETL is paused awaiting an operator decision.
             Renders above the currently-processing card so it's the first
             thing the user sees when the run blocks. -->
        <div
            v-if="errorPause"
            class="mb-4 bg-amber-950/40 border border-amber-700 rounded-lg p-4"
        >
            <div class="flex items-start justify-between gap-3 mb-2">
                <div class="flex-1 min-w-0">
                    <div class="text-amber-300 text-xs uppercase tracking-wider">
                        Paused on error
                    </div>
                    <div class="text-amber-100 text-lg font-semibold mt-0.5">
                        {{ errorPause.country }}
                        <span class="text-amber-300 text-sm font-normal">
                            ({{ errorPhaseLabel }} · level {{ errorPause.adm_level }})
                        </span>
                    </div>
                    <div class="text-amber-200/80 text-xs font-mono mt-1">
                        {{ errorPause.error_class }}
                    </div>
                </div>
            </div>
            <div
                class="bg-black/40 rounded p-2 text-xs font-mono text-amber-100 max-h-40 overflow-y-auto whitespace-pre-wrap"
                :title="errorPause.traceback || ''"
            >{{ errorMessageShort }}</div>
            <details v-if="errorPause.traceback" class="mt-2 text-[10px] text-amber-300/70">
                <summary class="cursor-pointer select-none">Full traceback</summary>
                <pre class="bg-black/40 rounded p-2 mt-1 overflow-x-auto whitespace-pre-wrap font-mono text-amber-100">{{ errorPause.traceback }}</pre>
            </details>
            <div class="mt-3 flex items-center gap-2 flex-wrap">
                <button
                    type="button"
                    @click="sendErrorResolution('skip')"
                    :disabled="!!errorActionPending"
                    class="text-sm px-3 py-1.5 rounded bg-blue-700 hover:bg-blue-600 disabled:bg-gray-700 disabled:cursor-not-allowed text-white font-semibold"
                    title="Mark this country as skipped and continue to the next."
                >
                    {{ errorActionPending === 'skip' ? 'Skipping…' : 'Skip this ' + (errorPhaseLabel || 'country').toLowerCase() }}
                </button>
                <button
                    type="button"
                    @click="sendErrorResolution('retry')"
                    :disabled="!!errorActionPending"
                    class="text-sm px-3 py-1.5 rounded bg-emerald-700 hover:bg-emerald-600 disabled:bg-gray-700 disabled:cursor-not-allowed text-white font-semibold"
                    title="Re-run this country. Useful if you fixed the underlying issue (e.g. restored a corrupted file)."
                >
                    {{ errorActionPending === 'retry' ? 'Retrying…' : 'Retry' }}
                </button>
                <button
                    type="button"
                    @click="sendErrorResolution('abort')"
                    :disabled="!!errorActionPending"
                    class="text-sm px-3 py-1.5 rounded bg-red-700 hover:bg-red-600 disabled:bg-gray-700 disabled:cursor-not-allowed text-white font-semibold"
                    title="Abort the entire run. Equivalent to clicking Halt."
                >
                    {{ errorActionPending === 'abort' ? 'Aborting…' : 'Abort run' }}
                </button>
            </div>
        </div>

        <!-- Phase P.1.1: stacked-progress-bars panel — full width, no preview.
             The minimap preview card was removed because (a) raster overlay
             during boundaries was misleading, (b) it visually competed with
             the bars even during WorldPop, and (c) the dedicated Jurisdiction
             Viewer is the proper place to inspect maps + rasters post-run.
             The bars carry all the in-flight information the operator needs. -->
        <div v-if="(isRunning || current || bars) && bars" class="mb-4">
            <StackedProgressBars :bars="bars" :current="current" :lifecycle="lifecycle" />
        </div>

        <JurisdictionCountsGrid
            :by-level="counts?.by_level || []"
            :total="counts?.total || 0"
            :total-with-pop="counts?.total_with_pop || 0"
            :total-sum-pop="counts?.total_sum_pop || 0"
            :sub-phase-hint="current?.sub_phase || ''"
        />

        <!-- P.1.2: PhaseSummary (the "Phase 1 — Boundaries / Phase 2 —
             Population countries-done" cards) removed — the stacked bars
             above carry the same information per-level with better
             granularity, so the two summary cards were redundant. -->

        <div
            v-if="failed"
            class="mb-4 text-sm rounded p-3 border"
            :class="stoppedOnException
                ? 'bg-amber-900/30 border-amber-800 text-amber-200'
                : 'bg-red-900/30 border-red-800 text-red-200'"
        >
            <div class="font-semibold">
                <template v-if="stoppedOnException">
                    Run halted on first exception (exit {{ failed.exit_code }}).
                </template>
                <template v-else>
                    Run failed (exit {{ failed.exit_code }}).
                </template>
            </div>
            <div v-if="failed.error" class="font-mono text-xs mt-1">{{ failed.error }}</div>
        </div>

        <div
            v-else-if="done && !isRunning"
            class="mb-4 bg-emerald-900/30 border border-emerald-800 text-emerald-200 text-sm rounded p-3"
        >
            <span class="font-semibold">Run completed.</span>
            Finished {{ done.finished_at }}.
        </div>

        <!-- Phase P.3: structured events surface (errors / warnings / info)
             above the developer log toggle. The log tail is hidden by default
             behind a toggle so the operator's first surface is the structured
             event feed, not a wall of text. -->
        <div class="mb-4">
            <EventToasts :events="events" />
        </div>

        <!-- P.1.2: Developer log details panel removed from the operator-
             facing UI. The structured event feed (EventToasts above) carries
             the actionable signal; raw log tail is available via the file
             system or `docker compose exec etl tail /var/www/html/scripts/etl/etl.log`
             for development. -->
        <!--
        <details class="rounded border border-gray-800">
            <summary class="cursor-pointer select-none px-3 py-2 text-xs text-gray-400 hover:bg-gray-900/40">
                Developer log
            </summary>
            <div class="border-t border-gray-800">
                <LogTailPanel
                    :lines="logTail"
                    :include-debug="includeDebug"
                    @update:include-debug="$emit('update:includeDebug', $event)"
                />
            </div>
        </details>
        -->

    </section>
</template>
