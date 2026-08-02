<script setup>
// GeodataPullPanel — the pull-engine run dashboard (GEODATA_PULL_ENGINE_PLAN.md §5).
//
// Self-contained: polls /api/setup/wizard/step2/pull-progress every 2 s while
// mounted (the Step-3 autoscale contract — the poll is ALWAYS armed, so a run
// started or resumed elsewhere appears within a poll). Renders the phase
// pipeline, per-kind progress bars, the per-worker claim strip (one line per
// lease — what every worker holds at this instant), the review census, and
// halt/resume controls. Renders nothing until a run exists.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { csrfFetch } from '@/lib/csrf'

const emit = defineEmits(['run-state'])

const data       = ref(null)   // { run, layers, workers, review }
const error      = ref('')
const actionBusy = ref(false)
const nowTick    = ref(Date.now())
let pollTimer  = null
let clockTimer = null

const run     = computed(() => data.value?.run ?? null)
const layers  = computed(() => data.value?.layers ?? [])
const workers = computed(() => data.value?.workers ?? [])
const review  = computed(() => data.value?.review ?? [])
const active  = computed(() => run.value && ['running', 'halted'].includes(run.value.status))

const PHASES = [
    { key: 'enumerating', kind: 'manifest',         label: 'Enumerate' },
    { key: 'boundaries',  kind: 'boundary_iso',     label: 'Boundaries' },
    { key: 'resolving',   kind: 'resolve_global',   label: 'Resolve' },
    { key: 'rasters',     kind: 'raster_iso',       label: 'Rasters' },
    { key: 'attribution', kind: 'attribution_pair', label: 'Attribution' },
    { key: 'finalizing',  kind: 'finalize_global',  label: 'Finalize' },
    { key: 'scanning',    kind: 'acceptance_scan',  label: 'Scan' },
]

const layerByKind = computed(() => {
    const m = {}
    for (const l of layers.value) m[l.kind] = l
    return m
})

const phaseIndex = computed(() => {
    if (!run.value) return -1
    if (run.value.phase === 'done') return PHASES.length
    return PHASES.findIndex(p => p.key === run.value.phase)
})

function phaseState(i) {
    if (phaseIndex.value === -1) return 'pending'
    if (i < phaseIndex.value) return 'done'
    if (i === phaseIndex.value) return 'current'
    return 'pending'
}

// Elapsed readouts tick locally between polls (nowTick refreshes each second).
function elapsedSince(iso) {
    if (!iso) return ''
    const s = Math.max(0, Math.floor((nowTick.value - new Date(iso).getTime()) / 1000))
    if (s < 60) return `${s}s`
    if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`
    return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`
}

function phaseElapsed(key) {
    const t = run.value?.phase_timestamps?.[key]
    if (!t?.started_at) return ''
    if (t.finished_at) {
        const s = Math.max(0, Math.floor((new Date(t.finished_at) - new Date(t.started_at)) / 1000))
        return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m`
    }
    return elapsedSince(t.started_at)
}

function pct(l) {
    if (!l || !l.total) return 0
    return Math.round(((l.total - l.open) / l.total) * 100)
}

async function fetchProgress() {
    try {
        const res = await fetch('/api/setup/wizard/step2/pull-progress', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
        if (!res.ok) { error.value = `Could not load pull-engine progress (HTTP ${res.status}).`; return }
        error.value = ''
        data.value = await res.json()
        // Let the parent react (e.g. hide the legacy bars panel while a pull
        // run is live — the two surfaces would otherwise fight for attention).
        emit('run-state', data.value?.run ?? null)
    } catch (e) {
        error.value = String(e)
    }
}

async function control(action) {
    actionBusy.value = true
    try {
        const res = await csrfFetch('/api/setup/wizard/step2/pull-control', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action }),
        })
        if (!res.ok) {
            const d = await res.json().catch(() => ({}))
            error.value = d.error || `Control '${action}' failed (HTTP ${res.status}).`
        }
        await fetchProgress()
    } finally {
        actionBusy.value = false
    }
}

defineExpose({ fetchProgress })

onMounted(() => {
    fetchProgress()
    pollTimer  = setInterval(fetchProgress, 2000)
    clockTimer = setInterval(() => { nowTick.value = Date.now() }, 1000)
})
onBeforeUnmount(() => {
    if (pollTimer)  clearInterval(pollTimer)
    if (clockTimer) clearInterval(clockTimer)
})
</script>

<template>
    <section v-if="run" class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <h2 class="text-white font-semibold">Multithreaded ingestion</h2>
                <span
                    class="text-xs px-2 py-0.5 rounded-full font-medium"
                    :class="{
                        'bg-emerald-900/60 text-emerald-300': run.status === 'running',
                        'bg-amber-900/60 text-amber-300':     run.status === 'halted' || run.paused,
                        'bg-sky-900/60 text-sky-300':         run.status === 'done',
                        'bg-red-900/60 text-red-300':         run.status === 'failed',
                    }"
                >
                    {{ run.paused ? 'paused (pg recovery)' : run.status }}
                </span>
            </div>
            <div class="flex gap-2">
                <button
                    v-if="run.status === 'running' && !run.halt_requested"
                    type="button" :disabled="actionBusy" @click="control('halt')"
                    class="text-xs px-3 py-1.5 rounded border border-amber-700 text-amber-300 hover:bg-amber-900/40 disabled:opacity-50"
                >
                    Halt (workers stop at next claim)
                </button>
                <button
                    v-if="run.status === 'halted' || run.halt_requested"
                    type="button" :disabled="actionBusy" @click="control('resume')"
                    class="text-xs px-3 py-1.5 rounded border border-emerald-700 text-emerald-300 hover:bg-emerald-900/40 disabled:opacity-50"
                >
                    Resume
                </button>
            </div>
        </div>

        <p v-if="error" class="text-red-400 text-xs mb-3">{{ error }}</p>
        <p v-if="run.last_error" class="text-amber-400/80 text-xs mb-3">{{ run.last_error }}</p>

        <!-- Phase pipeline -->
        <ol class="flex flex-wrap items-center gap-1 mb-5" aria-label="Pipeline phases">
            <template v-for="(p, i) in PHASES" :key="p.key">
                <li
                    class="flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full border"
                    :class="{
                        'border-emerald-800 bg-emerald-900/30 text-emerald-300': phaseState(i) === 'done',
                        'border-sky-700 bg-sky-900/40 text-sky-200 font-semibold': phaseState(i) === 'current',
                        'border-gray-800 text-gray-500': phaseState(i) === 'pending',
                    }"
                >
                    <span v-if="phaseState(i) === 'done'" aria-hidden="true">✓</span>
                    {{ p.label }}
                    <span v-if="phaseElapsed(p.key)" class="text-[10px] opacity-70">{{ phaseElapsed(p.key) }}</span>
                </li>
                <li v-if="i < PHASES.length - 1" class="text-gray-700 text-xs" aria-hidden="true">→</li>
            </template>
        </ol>

        <!-- Per-kind progress bars -->
        <div class="space-y-2.5 mb-5">
            <div v-for="p in PHASES" :key="p.kind">
                <template v-if="layerByKind[p.kind]">
                    <div class="flex justify-between text-xs mb-1">
                        <span class="text-gray-300">{{ p.label }}</span>
                        <span class="text-gray-400 tabular-nums">
                            {{ (layerByKind[p.kind].total - layerByKind[p.kind].open).toLocaleString() }}
                            / {{ layerByKind[p.kind].total.toLocaleString() }}
                            <span v-if="Number(layerByKind[p.kind].review)" class="text-amber-400"> · {{ layerByKind[p.kind].review }} review</span>
                            <span v-if="Number(layerByKind[p.kind].failed)" class="text-red-400"> · {{ layerByKind[p.kind].failed }} failed</span>
                        </span>
                    </div>
                    <div class="h-2 bg-gray-800 rounded overflow-hidden">
                        <div
                            class="h-full rounded transition-all duration-500"
                            :class="phaseState(PHASES.findIndex(x => x.kind === p.kind)) === 'current' ? 'bg-sky-500' : 'bg-emerald-600'"
                            :style="{ width: pct(layerByKind[p.kind]) + '%' }"
                        />
                    </div>
                </template>
            </div>
        </div>

        <!-- Per-worker claim strip: what every worker holds at this instant -->
        <div v-if="active" class="mb-4">
            <h3 class="text-gray-300 text-xs font-semibold uppercase tracking-wide mb-2">
                Workers ({{ workers.length }})
            </h3>
            <div v-if="workers.length === 0" class="text-gray-500 text-xs">
                No live workers — the ETL supervisor seeds the pool within a few seconds of the run starting.
            </div>
            <ul v-else class="space-y-1">
                <li
                    v-for="w in workers" :key="w.id"
                    class="flex items-center justify-between text-xs bg-gray-800/60 rounded px-2.5 py-1.5"
                >
                    <span class="flex items-center gap-2 min-w-0">
                        <span
                            class="w-1.5 h-1.5 rounded-full shrink-0"
                            :class="w.claim_label ? 'bg-sky-400 animate-pulse' : 'bg-gray-600'"
                            aria-hidden="true"
                        />
                        <span class="truncate" :class="w.claim_label ? 'text-gray-200' : 'text-gray-500'">
                            {{ w.claim_label || 'idle — waiting for the next claim' }}
                        </span>
                    </span>
                    <span v-if="w.claim_started_at" class="text-gray-500 tabular-nums shrink-0 ml-3">
                        {{ elapsedSince(w.claim_started_at) }}
                    </span>
                </li>
            </ul>
        </div>

        <!-- Review census -->
        <div v-if="review.length">
            <h3 class="text-amber-300 text-xs font-semibold uppercase tracking-wide mb-2">
                Needs review ({{ review.length }}) — these never sink the run
            </h3>
            <ul class="space-y-1 max-h-48 overflow-y-auto pr-1">
                <li v-for="(r, i) in review" :key="i" class="text-xs text-gray-400">
                    <span class="text-gray-300">{{ r.kind }}</span>
                    <span v-if="r.iso_code"> · {{ r.iso_code }}</span><span v-if="r.adm_level !== null"> L{{ r.adm_level }}</span>
                    <span v-if="r.reason" class="text-gray-500"> — {{ r.reason }}</span>
                </li>
            </ul>
        </div>

        <p v-if="run.status === 'done'" class="text-emerald-300 text-sm mt-2">
            Ingestion complete — {{ run.items_done.toLocaleString() }} items done<template v-if="run.items_review"> ·
            {{ run.items_review }} for review</template>. Review any flags below, then continue.
        </p>
    </section>
</template>
