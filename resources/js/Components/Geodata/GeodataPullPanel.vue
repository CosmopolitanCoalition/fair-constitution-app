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

// In-flight items enriched with their live per-feature progress (metrics.live
// — real counts from the importer's bar hooks, the legacy stacked-bar detail).
// Sorted LONGEST-RUNNING FIRST (operator ask 2026-08-02): rows keep their
// place for their whole life — new claims append at the bottom, so the list
// never reshuffles under the eye.
const inflight = computed(() => (data.value?.inflight ?? []).map(it => {
    let live = null
    try {
        const m = typeof it.metrics === 'string' ? JSON.parse(it.metrics) : it.metrics
        live = m?.live ?? null
    } catch { /* not yet written */ }
    return { ...it, live }
}).sort((a, b) => (a.started_at < b.started_at ? -1 : a.started_at > b.started_at ? 1 : (a.id < b.id ? -1 : 1))))
const idleWorkers = computed(() => Math.max(0, workers.value.length - inflight.value.length))

// Overall planet progress — loaded jurisdictions vs the metadata census.
const world = computed(() => data.value?.world ?? null)
const worldPct = computed(() => {
    if (!world.value?.expected) return 0
    return Math.min(100, Math.round(world.value.loaded / world.value.expected * 100))
})

// Resolve-phase live signal: parent chains built vs total ADM2+ rows —
// the unparented count drains as each set-based strategy pass commits.
const resolve = computed(() => data.value?.resolve ?? null)
const resolvePct = computed(() => {
    if (!resolve.value?.total) return 0
    return Math.min(100, Math.round(
        (resolve.value.total - resolve.value.unparented) / resolve.value.total * 100))
})

function itemLabel(it) {
    const iso = it.iso_code ? ` · ${it.iso_code}` : ''
    const lvl = it.adm_level !== null && it.adm_level !== undefined ? ` L${it.adm_level}` : ''
    const kind = { boundary_iso: 'boundaries', raster_iso: 'rasters', attribution_pair: 'attribution' }[it.kind] ?? it.kind
    return kind + iso + lvl
}

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

        <!-- Overall planet progress — jurisdictions loaded vs the metadata
             census (the legacy overall-counts bar, reborn) -->
        <div v-if="world && world.expected" class="mb-5">
            <div class="flex justify-between text-xs mb-1">
                <span class="text-gray-200 font-semibold">Jurisdictions loaded</span>
                <span class="text-gray-300 tabular-nums">
                    {{ world.loaded.toLocaleString() }} / {{ world.expected.toLocaleString() }}
                    <span class="text-gray-500">· {{ worldPct }}%</span>
                </span>
            </div>
            <div class="h-3 bg-gray-800 rounded overflow-hidden">
                <div
                    class="h-full rounded bg-emerald-500 transition-all duration-700"
                    :style="{ width: worldPct + '%' }"
                />
            </div>
        </div>

        <!-- Resolve-phase live parenting bar — visible only while the
             resolve barrier chains the planet's parent hierarchy -->
        <div v-if="resolve && resolve.total" class="mb-5">
            <div class="flex justify-between text-xs mb-1">
                <span class="text-gray-200 font-semibold">Parent chains (resolve)</span>
                <span class="text-gray-300 tabular-nums">
                    {{ (resolve.total - resolve.unparented).toLocaleString() }} / {{ resolve.total.toLocaleString() }}
                    <span class="text-gray-500">· {{ resolvePct }}%</span>
                </span>
            </div>
            <div class="h-3 bg-gray-800 rounded overflow-hidden">
                <div
                    class="h-full rounded bg-violet-500 transition-all duration-700"
                    :style="{ width: resolvePct + '%' }"
                />
            </div>
            <div v-if="resolve.unparented === resolve.total" class="text-[11px] text-gray-500 mt-1">
                strategy passes run as set-based SQL — the count moves in steps as each pass commits
            </div>
        </div>

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

        <!-- What every worker holds at this instant, with live per-feature
             progress (the legacy stacked-bar detail, one mini bar per country) -->
        <div v-if="active" class="mb-4">
            <h3 class="text-gray-300 text-xs font-semibold uppercase tracking-wide mb-2">
                Workers ({{ workers.length }})
                <span v-if="workers.length" class="text-gray-500 normal-case font-normal">
                    — {{ inflight.length }} working<template v-if="idleWorkers"> · {{ idleWorkers }} idle</template>
                </span>
            </h3>
            <div v-if="workers.length === 0" class="text-gray-500 text-xs">
                No live workers — the ETL supervisor seeds the pool within a few seconds of the run starting.
            </div>
            <ul v-else class="space-y-1.5">
                <li
                    v-for="it in inflight" :key="it.id"
                    class="text-xs bg-gray-800/60 rounded px-2.5 py-2"
                >
                    <div class="flex items-center justify-between mb-1">
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="w-1.5 h-1.5 rounded-full shrink-0 bg-sky-400 animate-pulse" aria-hidden="true" />
                            <span class="text-gray-200 font-medium">{{ itemLabel(it) }}</span>
                            <span v-if="it.live" class="text-gray-400 truncate">{{ it.live.label }}</span>
                        </span>
                        <span class="text-gray-500 tabular-nums shrink-0 ml-3">
                            <template v-if="it.live && it.live.total">
                                {{ it.live.current.toLocaleString() }} / {{ it.live.total.toLocaleString() }} {{ it.live.unit }} ·
                            </template>
                            {{ elapsedSince(it.started_at) }}
                        </span>
                    </div>
                    <div class="h-1.5 bg-gray-900 rounded overflow-hidden">
                        <div
                            v-if="it.live && it.live.total"
                            class="h-full rounded bg-sky-500 transition-all duration-700"
                            :style="{ width: Math.min(100, Math.round(it.live.current / it.live.total * 100)) + '%' }"
                        />
                        <div v-else class="h-full w-1/4 rounded bg-sky-800 animate-pulse" />
                    </div>
                </li>
                <li v-if="idleWorkers" class="text-xs text-gray-500 px-2.5 py-1">
                    {{ idleWorkers }} worker{{ idleWorkers > 1 ? 's' : '' }} between claims —
                    yield backoff (a giant holds the parse floor) or waiting for work
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
