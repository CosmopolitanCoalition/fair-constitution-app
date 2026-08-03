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

// Per-level population census — the legacy by-level counts, reborn.
const levels = computed(() => data.value?.levels ?? [])
const LEVEL_NAMES = { 1: 'Countries', 2: 'States/Provinces', 3: 'Counties',
                      4: 'Localities', 5: 'Sub-localities', 6: 'Neighborhoods' }

// Resolve-phase live signal: parent chains built vs total ADM2+ rows —
// the unparented count drains as each set-based strategy pass commits.
const resolve = computed(() => data.value?.resolve ?? null)
// The six scan detectors run Laravel-side in Horizon, so they never appear
// in the worker strips (those are Python ETL leases). Without these chips
// the scan reads as one opaque lane when it is actually running 5-6 wide.
const scan = computed(() => data.value?.scan ?? null)
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

// Honest per-item ETA from the live bar: elapsed × remaining/done. Shown
// only once a real rate exists (>=2% done), so it never fabricates.
function itemEta(it) {
    if (!it.live || !it.live.total || !it.live.current) return ''
    const frac = it.live.current / it.live.total
    if (frac < 0.02 || frac >= 1) return ''
    const elapsedMs = nowTick.value - new Date(it.started_at + 'Z').getTime()
    if (elapsedMs <= 0) return ''
    const remainMs = elapsedMs * (1 - frac) / frac
    const m = Math.round(remainMs / 60000)
    if (m < 1) return '· <1m left'
    if (m < 90) return `· ~${m}m left`
    return `· ~${Math.round(m / 60)}h left`
}

// The breadcrumb models the TRUE dependency shape, not the phase pointer's
// flat walk (operator, 2026-08-03; combined-bubble form same day). The two
// ingest fan-outs run overlapped and share one bubble; the two derivation
// steps share the next:
//
//   Enumerate => [Boundaries + Rasters] => [Resolve + Attribution] => Finalize => Scan
//
// Each group is ONE bubble; phases inside it carry their own check/elapsed.
const GROUPS = [
    [ { key: 'enumerating', kind: 'manifest',         label: 'Enumerate' } ],
    [ { key: 'boundaries',  kind: 'boundary_iso',     label: 'Boundaries' },
      { key: 'rasters',     kind: 'raster_iso',       label: 'Rasters' } ],
    [ { key: 'resolving',   kind: 'resolve_global',   label: 'Resolve' },
      { key: 'attribution', kind: 'attribution_pair', label: 'Attribution' } ],
    [ { key: 'finalizing',  kind: 'finalize_global',  label: 'Finalize' } ],
    [ { key: 'scanning',    kind: 'acceptance_scan',  label: 'Scan' } ],
]

// Flat phase list in pipeline order (the per-kind bars iterate this).
const ALL_PHASES = GROUPS.flat()

// WHICH BARS EARN THEIR ROW (operator, 2026-08-03 — "bring back the bars
// you took away, you took away the wrong ones"). I had dropped Boundaries
// and Rasters, which carry real counts (232/232, 229/229), and kept the
// single-item barriers that only ever read 0/1 or 1/1 and say nothing.
//
// The rule is now the count itself: a bar appears when its family holds
// more than one item. Barriers stay hidden until they fan out — Resolve is
// 0/1 as a coordinator but 232 per-country children once it does, and that
// is the bar worth showing, so each phase prefers the FAMILY MEMBER WITH
// THE MOST ITEMS.
const FAMILY_OF = {
    boundary_iso:     ['boundary_iso', 'boundary_range'],
    raster_iso:       ['raster_iso', 'raster_range'],
    resolve_global:   ['resolve_range', 'resolve_global'],
    attribution_pair: ['attribution_pair', 'attribution_range'],
}

const BAR_ROWS = computed(() => {
    const rows = []
    for (const p of ALL_PHASES) {
        const kinds = FAMILY_OF[p.kind] ?? [p.kind]
        let best = null
        for (const k of kinds) {
            const l = layerByKind.value[k]
            if (l && (best === null || Number(l.total) > Number(best.total))) best = l
        }
        if (best && Number(best.total) > 1) rows.push({ phase: p, layer: best })
    }
    return rows
})

// A bubble is current if any of its phases is, done only when all are.
function groupState(group) {
    const states = group.map(p => chipState(p))
    if (states.includes('current')) return 'current'
    if (states.every(s => s === 'done')) return 'done'
    return 'pending'
}

// ONE timer per bubble, at its end (operator, 2026-08-03): earliest member
// start → latest member finish; ticking while any member is unfinished.
function groupElapsed(group) {
    const stamps = group
        .map(p => run.value?.phase_timestamps?.[p.key])
        .filter(t => t?.started_at)
    if (!stamps.length) return ''
    const start = Math.min(...stamps.map(t => new Date(t.started_at).getTime()))
    const open = stamps.some(t => !t.finished_at)
        || stamps.length < group.length && groupState(group) !== 'done'
    const end = open ? nowTick.value
        : Math.max(...stamps.map(t => new Date(t.finished_at).getTime()))
    const s = Math.max(0, Math.floor((end - start) / 1000))
    if (s < 60) return `${s}s`
    if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`
    return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`
}

const layerByKind = computed(() => {
    const m = {}
    for (const l of layers.value) m[l.kind] = l
    return m
})

// Chip state from ITEM COUNTS first, phase timestamps second — the pointer
// alone lies under the overlap (rasters finish while the pointer still says
// boundaries; the old index-ordered chips showed them pending regardless).
// A kind with items actively RUNNING is current even before any completes —
// without that, rasters sat unbolded through the whole overlap while lanes
// were visibly loading tiles (operator-caught, twice).
function chipState(p) {
    const r = run.value
    if (!r) return 'pending'
    if (r.phase === 'done') return 'done'
    const t = r.phase_timestamps?.[p.key]
    const l = layerByKind.value[p.kind]
    if (t?.finished_at || (l && l.total > 0 && l.open === 0)) return 'done'
    if (r.phase === p.key || t?.started_at
        || (l && l.total > 0 && (Number(l.running) > 0 || l.open < l.total))) return 'current'
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
                <h2 class="text-white font-semibold">GeoData Ingestion</h2>
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

        <!-- Phase pipeline — one bubble per dependency stage:
             Enumerate => [Boundaries + Rasters] => [Resolve + Attribution] => Finalize => Scan -->
        <ol class="flex flex-wrap items-center gap-1.5 mb-5" aria-label="Pipeline phases">
            <template v-for="(group, gi) in GROUPS" :key="gi">
                <li
                    class="flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full border"
                    :class="{
                        'border-emerald-800 bg-emerald-900/30 text-emerald-300': groupState(group) === 'done',
                        'border-sky-700 bg-sky-900/40 text-sky-200': groupState(group) === 'current',
                        'border-gray-800 text-gray-500': groupState(group) === 'pending',
                    }"
                >
                    <template v-for="(p, pi) in group" :key="p.key">
                        <span v-if="pi > 0" class="opacity-50" aria-hidden="true">+</span>
                        <span class="flex items-center gap-1"
                              :class="{
                                  'text-emerald-300': chipState(p) === 'done' && groupState(group) !== 'done',
                                  'font-semibold': chipState(p) === 'current',
                                  'opacity-60': chipState(p) === 'pending' && groupState(group) === 'current',
                              }">
                            <span v-if="chipState(p) === 'done'" aria-hidden="true">✓</span>
                            {{ p.label }}
                        </span>
                    </template>
                    <span v-if="groupElapsed(group)" class="text-[10px] opacity-70">{{ groupElapsed(group) }}</span>
                </li>
                <li v-if="gi < GROUPS.length - 1" class="text-gray-700 text-xs" aria-hidden="true">⇒</li>
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

        <!-- Per-level population census (the legacy by-level counts) -->
        <div v-if="levels.length" class="mb-5">
            <h3 class="text-gray-300 text-xs font-semibold uppercase tracking-wide mb-2">
                Population by level
            </h3>
            <table class="w-full text-xs tabular-nums">
                <tbody>
                    <tr v-for="l in levels" :key="l.adm_level" class="border-b border-gray-800/60">
                        <td class="py-1 text-gray-400">L{{ l.adm_level }} {{ LEVEL_NAMES[l.adm_level] ?? '' }}</td>
                        <td class="py-1 text-right text-gray-300">{{ Number(l.with_pop).toLocaleString() }} / {{ Number(l.rows).toLocaleString() }} populated</td>
                        <td class="py-1 text-right" :class="Number(l.pop_sum) > 0 ? 'text-emerald-300' : 'text-gray-600'">
                            {{ Number(l.pop_sum).toLocaleString() }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Per-kind progress bars -->
        <div class="space-y-2.5 mb-5">
            <div v-for="row in BAR_ROWS" :key="row.phase.key">
                <div class="flex justify-between text-xs mb-1">
                    <span class="text-gray-300">{{ row.phase.label }}</span>
                    <span class="text-gray-400 tabular-nums">
                        {{ (row.layer.total - row.layer.open).toLocaleString() }}
                        / {{ row.layer.total.toLocaleString() }}
                        <span v-if="Number(row.layer.review)" class="text-amber-400"> · {{ row.layer.review }} review</span>
                        <span v-if="Number(row.layer.failed)" class="text-red-400"> · {{ row.layer.failed }} failed</span>
                    </span>
                </div>
                <div class="h-2 bg-gray-800 rounded overflow-hidden">
                    <div
                        class="h-full rounded transition-all duration-500"
                        :class="chipState(row.phase) === 'current' ? 'bg-sky-500' : 'bg-emerald-600'"
                        :style="{ width: pct(row.layer) + '%' }"
                    />
                </div>
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
                            {{ elapsedSince(it.started_at) }} {{ itemEta(it) }}
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

        <!-- Acceptance-scan detectors. Horizon-side work, invisible to the
             worker strips above — six chips so the scan is never a single
             opaque bar again. -->
        <div v-if="scan && scan.detectors" class="mb-5">
            <h3 class="text-gray-200 text-xs font-semibold uppercase tracking-wide mb-2">
                Acceptance scan — {{ scan.detectors.filter(d => d.state === 'done').length }} / {{ scan.detectors.length }} detectors
            </h3>
            <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-1.5">
                <li v-for="d in scan.detectors" :key="d.key"
                    class="flex items-center justify-between gap-2 text-xs px-2.5 py-1.5 rounded border"
                    :class="{
                        'border-emerald-800 bg-emerald-900/20 text-emerald-300': d.state === 'done',
                        'border-sky-700 bg-sky-900/30 text-sky-200': d.state === 'running',
                        'border-amber-800 bg-amber-900/20 text-amber-300': d.state === 'stalled',
                        'border-red-800 bg-red-900/20 text-red-300': d.state === 'error',
                        'border-gray-800 text-gray-500': d.state === 'pending',
                    }">
                    <span class="truncate">
                        <span v-if="d.state === 'done'" aria-hidden="true">✓ </span>
                        <span v-else-if="d.state === 'error'" aria-hidden="true">✕ </span>
                        <span v-else-if="d.state === 'stalled'" aria-hidden="true">⚠ </span>
                        <span v-else-if="d.state === 'running'" aria-hidden="true">▶ </span>
                        {{ d.label }}
                    </span>
                    <span class="tabular-nums shrink-0 text-[11px] opacity-80">
                        <template v-if="d.state === 'done'">{{ d.flags.toLocaleString() }} flag{{ d.flags === 1 ? '' : 's' }}</template>
                        <template v-else-if="d.state === 'running'">{{ Math.floor(d.elapsed_s / 60) }}m {{ d.elapsed_s % 60 }}s</template>
                        <template v-else-if="d.state === 'stalled'">stalled {{ Math.floor(d.elapsed_s / 60) }}m — will retry</template>
                        <template v-else-if="d.state === 'error'">errored</template>
                        <template v-else>queued</template>
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
