<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppShell from '@/Layouts/AppShell.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import { csrfFetch } from '@/lib/csrf'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

const props = defineProps({
    step: { type: Number, required: true },
    settings: { type: Object, required: true },
    root_jurisdiction: { type: Object, default: null },
    root_legislature_id: { type: String, default: null },
})

// Gate on the legislature existing, but address it by the root jurisdiction's
// slug (canonical, parity with the jurisdiction viewer). Fall back to the UUID
// if the slug somehow isn't present — the mapper route dual-accepts both.
const mapperHref = props.root_legislature_id
    ? `/legislatures/${props.root_jurisdiction?.slug ?? props.root_legislature_id}?setup=1`
    : null

const summary = ref(null)
const summaryError = ref('')

async function loadSummary() {
    try {
        const res = await fetch('/api/setup/wizard/step3/summary', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) {
            summaryError.value = `Could not load apportionment summary (HTTP ${res.status}).`
            return
        }
        summary.value = await res.json()
    } catch (e) {
        summaryError.value = String(e)
    }
}

// ── Autoscale dashboard (pull engine, 2026-07-19) ────────────────────────
// Map-data acceptance kicks off the full-scale run: every jurisdiction gets
// a sized legislature + a founding district map. This panel polls the run
// every 2 s with the Step-2 contract: the poll is ALWAYS armed while the
// page is open (even with no run yet, or a halted one — a run created or
// resumed elsewhere appears within a poll), and stops only on done/failed.
// Every action handler re-arms it.

const autoscale = ref(null)      // { run, layers, precompute, live_items, review_items }
const autoscaleError = ref('')
const actionBusy = ref(false)
let pollTimer = null
let summaryTick = 0

const run = computed(() => autoscale.value?.run ?? null)
const layers = computed(() => autoscale.value?.layers ?? [])
const precompute = computed(() => autoscale.value?.precompute ?? null)
const runActive = computed(() => run.value && ['queued', 'sizing', 'mapping'].includes(run.value.status))
const precomputeOpen = computed(() =>
    precompute.value && (precompute.value.done + precompute.value.failed) < precompute.value.total)

async function fetchAutoscale() {
    try {
        const res = await fetch('/api/setup/wizard/step3/autoscale-progress', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) {
            autoscaleError.value = `Could not load autoscale progress (HTTP ${res.status}).`
            return
        }
        autoscaleError.value = ''
        const data = await res.json()
        const wasActive = runActive.value
        autoscale.value = data

        const status = autoscale.value?.run?.status
        if (wasActive && !runActive.value) {
            loadSummary()
        }
        // The Apportionment card reads the legislatures table — refresh its
        // headline every ~20 s in EVERY active phase, so the numbers move
        // without a manual page reload.
        if (runActive.value && ++summaryTick % 10 === 0) {
            loadSummary()
        }
        // Terminal short-circuit only — halted/null keep polling (Resume or
        // an accept elsewhere must surface without a page reload).
        if ((status === 'done' || status === 'failed') && pollTimer) {
            stopPolling()
        }
    } catch (e) {
        autoscaleError.value = String(e)
    }
}

function startPolling() {
    stopPolling()
    pollTimer = setInterval(fetchAutoscale, 2000)
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

async function haltRun() {
    if (!confirm('Halt the full-scale run? Workers stop at their next claim boundary; everything already committed stays. You can resume any time.')) return
    actionBusy.value = true
    try {
        await csrfFetch('/api/setup/wizard/step3/autoscale-halt', { method: 'POST' })
        await fetchAutoscale()
        startPolling()
    } finally {
        actionBusy.value = false
    }
}

async function resumeRun(requeueReview = false) {
    actionBusy.value = true
    try {
        const res = await csrfFetch('/api/setup/wizard/step3/autoscale-resume', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ requeue_review: requeueReview }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            autoscaleError.value = data.error || `resume failed (HTTP ${res.status})`
            return
        }
        await fetchAutoscale()
        startPolling()
    } finally {
        actionBusy.value = false
    }
}

// ── Number tweening (the Step-2 feel) ────────────────────────────────────
// The backend counts are fresh every 2 s poll; tween the displayed numbers
// between polls so counters roll instead of jumping (simplified from
// StackedProgressBars' P.1.1 tween — same ease-out, adaptive-free).
const _tweened = ref({})
const _anim = {}
function _easeOutCubic(t) { return 1 - Math.pow(1 - t, 3) }
function tweenTo(key, target) {
    const start = _tweened.value[key] ?? target
    if (start === target) { _tweened.value = { ..._tweened.value, [key]: target }; return }
    const st = { t0: performance.now(), start, target, dur: 1700 }
    if (_anim[key]?.frame) cancelAnimationFrame(_anim[key].frame)
    _anim[key] = st
    const stepFn = () => {
        const p = Math.min((performance.now() - st.t0) / st.dur, 1)
        const v = Math.round(st.start + (st.target - st.start) * _easeOutCubic(p))
        _tweened.value = { ..._tweened.value, [key]: v }
        if (p < 1) st.frame = requestAnimationFrame(stepFn)
    }
    st.frame = requestAnimationFrame(stepFn)
}
function shown(key, fallback = 0) {
    return (_tweened.value[key] ?? fallback).toLocaleString()
}
watch(autoscale, (data) => {
    const r = data?.run
    if (!r) return
    tweenTo('singles_done', r.singles_done ?? 0)
    tweenTo('sweeps_done', r.sweeps_done ?? 0)
    if (r.sized_live != null) tweenTo('sized_live', r.sized_live)
    for (const l of data?.layers ?? []) tweenTo(`layer:${l.key}`, l.done)
    if (data?.precompute) tweenTo('precompute', data.precompute.done)
})

function fmtEta(seconds) {
    if (seconds == null) return '—'
    if (seconds < 90) return `${seconds}s`
    const h = Math.floor(seconds / 3600)
    const m = Math.round((seconds % 3600) / 60)
    if (h >= 48) return `${Math.floor(h / 24)}d ${h % 24}h`
    if (h >= 1) return `${h}h ${m}m`
    return `${m}m`
}

function pct(done, total) {
    if (!total) return 0
    return Math.min(100, Math.round((done / total) * 1000) / 10)
}

function layerLabel(l) {
    const kind = l.kind === 'single' ? 'leaf councils' : 'sweeps'
    return `ADM${l.adm_level} ${kind}`
}

const phaseLabel = computed(() => {
    if (!run.value) return ''
    switch (run.value.status) {
        case 'queued': return 'Queued — the pump starts it within a minute'
        case 'sizing': return 'Phase A — sizing every legislature (cube-root law, True All Scale)'
        case 'mapping':
            return precomputeOpen.value
                ? 'Phase B — leaf councils + geometry precompute (borders paid once, not 48k times)'
                : 'Phase B — drawing every founding district map (bottom-up: small scopes first, giants last)'
        case 'done': return 'Complete — every jurisdiction has a legislature and a founding map'
        case 'halted': return 'Halted by operator — resume any time'
        case 'failed': return 'Failed — see the error below'
        default: return run.value.status
    }
})

onMounted(async () => {
    await loadSummary()
    await fetchAutoscale()
    // ALWAYS arm unless the run is already terminal — a null or halted run
    // still polls (the old conditional arming was why the page froze until
    // a manual refresh).
    const status = run.value?.status
    if (status !== 'done' && status !== 'failed') {
        startPolling()
    }
})

onBeforeUnmount(stopPolling)
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-8 w-full">
            <SetupStepper :current="3" :completed="settings.setup_step_completed" />

            <header class="mt-8 mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    Build Your Districts
                </h1>
                <p class="text-gray-300 leading-relaxed">
                    Accepting the map data kicked off the <strong>full-scale build</strong>: every
                    jurisdiction gets a legislature sized by the cube-root law, and every legislature
                    gets a founding district map — real mixed-autoseed sweeps for jurisdictions with
                    constituents, single at-large councils for the leaves. You can walk away; the run
                    self-heals from any crash within minutes and this page tracks it live.
                </p>
            </header>

            <!-- Autoscale run dashboard -->
            <section
                v-if="run"
                class="rounded-lg p-5 mb-6 border"
                :class="{
                    'bg-blue-900/20 border-blue-800/50': runActive,
                    'bg-emerald-900/20 border-emerald-800/50': run.status === 'done',
                    'bg-amber-900/20 border-amber-800/50': run.status === 'halted',
                    'bg-red-900/20 border-red-800/50': run.status === 'failed',
                }"
            >
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div class="flex items-baseline gap-2">
                        <span v-if="runActive" class="inline-block w-2 h-2 rounded-full bg-blue-400 animate-pulse"></span>
                        <span v-else-if="run.status === 'done'" class="text-emerald-400 text-lg">✓</span>
                        <h2 class="font-semibold"
                            :class="{
                                'text-blue-200': runActive,
                                'text-emerald-200': run.status === 'done',
                                'text-amber-200': run.status === 'halted',
                                'text-red-200': run.status === 'failed',
                            }">
                            {{ phaseLabel }}
                        </h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="runActive && !run.halt_requested"
                            @click="haltRun"
                            :disabled="actionBusy"
                            class="text-xs px-3 py-1.5 rounded border border-amber-700 text-amber-300 hover:bg-amber-900/40 transition-colors"
                        >
                            Halt
                        </button>
                        <span v-else-if="runActive && run.halt_requested" class="text-xs text-amber-300 italic">
                            halting at the next boundary…
                        </span>
                        <button
                            v-if="run.status === 'halted'"
                            @click="resumeRun(false)"
                            :disabled="actionBusy"
                            class="text-xs px-3 py-1.5 rounded border border-emerald-700 text-emerald-300 hover:bg-emerald-900/40 transition-colors"
                        >
                            Resume
                        </button>
                    </div>
                </div>

                <!-- Headline counters -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4">
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Parents sized</div>
                        <div class="text-white text-lg font-semibold mt-1 tabular-nums">{{ run.sized_parents.toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Leaves sized</div>
                        <div class="text-white text-lg font-semibold mt-1 tabular-nums">{{ run.sized_leaves.toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Sweep rate (30 min)</div>
                        <div class="text-white text-lg font-semibold mt-1 tabular-nums">
                            {{ run.sweeps_per_hour != null && run.sweeps_per_hour > 0 ? `${run.sweeps_per_hour.toLocaleString()}/h` : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">ETA (sweeps)</div>
                        <div class="text-white text-lg font-semibold mt-1 tabular-nums">{{ fmtEta(run.eta_seconds) }}</div>
                    </div>
                    <div v-if="run.workers_target">
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Workers</div>
                        <div class="text-white text-lg font-semibold mt-1 tabular-nums">
                            {{ run.workers }}<span class="text-gray-500 text-sm font-normal">/{{ run.workers_target }}</span>
                            <span v-if="run.paused_until" class="text-amber-300 text-xs font-normal ml-1">paused (pg recovering)</span>
                        </div>
                    </div>
                </div>

                <!-- Overall progress bars -->
                <div class="space-y-3">
                    <!-- Live sizing bar: during Phase A the phase counters only
                         land at phase end — the legislatures count is the real
                         heartbeat, polled every 2s. -->
                    <div v-if="run.sized_live != null && run.sizing_total">
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span>Legislatures sized (live)</span>
                            <span class="tabular-nums">{{ shown('sized_live', run.sized_live) }} / {{ run.sizing_total.toLocaleString() }}</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded overflow-hidden">
                            <div class="h-full bg-emerald-500 transition-all" :style="{ width: pct(run.sized_live, run.sizing_total) + '%' }"></div>
                        </div>
                    </div>
                    <div v-if="precompute && precompute.total > 0">
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span>Geometry precompute (sibling borders, paid once)</span>
                            <span class="tabular-nums">{{ shown('precompute', precompute.done) }} / {{ precompute.total.toLocaleString() }}
                                <span v-if="precompute.failed" class="text-amber-400">· {{ precompute.failed }} fall back live</span>
                            </span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded overflow-hidden">
                            <div class="h-full bg-purple-500 transition-all" :style="{ width: pct(precompute.done + precompute.failed, precompute.total) + '%' }"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span>Leaf councils (single at-large districts)</span>
                            <span class="tabular-nums">{{ shown('singles_done', run.singles_done) }} / {{ run.singles_total.toLocaleString() }}</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded overflow-hidden">
                            <div class="h-full bg-teal-500 transition-all" :style="{ width: pct(run.singles_done, run.singles_total) + '%' }"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span>District-map sweeps (jurisdictions with constituents)</span>
                            <span class="tabular-nums">{{ shown('sweeps_done', run.sweeps_done) }} / {{ run.sweeps_total.toLocaleString() }}</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded overflow-hidden">
                            <div class="h-full bg-blue-500 transition-all" :style="{ width: pct(run.sweeps_done, run.sweeps_total) + '%' }"></div>
                        </div>
                    </div>
                </div>

                <!-- Per-ADM-layer bars (bottom-up: deepest first — the order the
                     run actually works in; the big scopes are the last bars) -->
                <div v-if="layers.length" class="mt-4 border-t border-gray-700/50 pt-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide mb-2">By layer (bottom-up)</div>
                    <div class="space-y-2">
                        <div v-for="l in layers" :key="l.key">
                            <div class="flex justify-between text-xs mb-0.5"
                                 :class="l.status === 'done' ? 'text-gray-500' : 'text-gray-400'">
                                <span>
                                    <span v-if="l.status === 'done'" class="text-emerald-500 mr-1">✓</span>
                                    <span v-else-if="l.status === 'running'" class="inline-block w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse mr-1"></span>
                                    {{ layerLabel(l) }}
                                    <span v-if="l.review" class="text-amber-400 ml-1">· {{ l.review }} review</span>
                                </span>
                                <span class="tabular-nums">{{ shown(`layer:${l.key}`, l.done) }} / {{ l.total.toLocaleString() }}</span>
                            </div>
                            <div class="h-1.5 bg-gray-800 rounded overflow-hidden">
                                <div class="h-full transition-all"
                                     :class="l.kind === 'single' ? 'bg-teal-500' : 'bg-blue-500'"
                                     :style="{ width: pct(l.done, l.total) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Informational drift (seating law: never forced) -->
                <p v-if="run.drifted_done > 0" class="text-gray-400 text-xs mt-3">
                    {{ run.drifted_done.toLocaleString() }} completed maps seat a total that differs from their
                    legislature's apportioned seats (net {{ run.net_drift > 0 ? '+' : '' }}{{ run.net_drift.toLocaleString() }}).
                    That drift is informational — the seating law forbids forcing totals; it's the drawing's
                    honest posture, revisitable per legislature in the mapper.
                </p>

                <p v-if="run.last_error" class="text-red-300 text-xs mt-3 font-mono break-all">
                    Last error: {{ run.last_error }}
                </p>

                <!-- THE WORKER STRIP: one honest line per live worker — what
                     each one holds RIGHT NOW (fast sweeps blink through the
                     scope list below; this never lies about the pool). -->
                <div v-if="autoscale.workers_detail?.length" class="mt-4 border-t border-gray-700/50 pt-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide mb-2">
                        Workers ({{ autoscale.workers_detail.length }})
                    </div>
                    <ul class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        <li v-for="w in autoscale.workers_detail" :key="w.id" class="flex items-baseline gap-2">
                            <span class="inline-block w-1.5 h-1.5 rounded-full"
                                  :class="w.claim_label ? 'bg-blue-400 animate-pulse' : 'bg-gray-600'"></span>
                            <span class="text-gray-500 text-xs font-mono">{{ w.id }}</span>
                            <span v-if="w.claim_label" class="text-gray-200 truncate">
                                {{ w.claim_label }}
                                <span v-if="w.claim_secs != null" class="text-gray-500 text-xs">· {{ w.claim_secs }}s</span>
                            </span>
                            <span v-else class="text-gray-500 italic text-xs">between claims</span>
                        </li>
                    </ul>
                </div>

                <!-- Live scopes: the real in-flight work units (Earth's giant
                     provinces show individually while Earth sweeps). -->
                <div v-if="autoscale.live_items?.length" class="mt-4 border-t border-gray-700/50 pt-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide mb-2">Sweeping now</div>
                    <ul class="space-y-1 text-sm">
                        <li v-for="it in autoscale.live_items" :key="`${it.legislature_id}:${it.jurisdiction_id}`" class="flex items-baseline gap-2">
                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                            <a :href="`/legislatures/${it.jurisdiction_slug}`" target="_blank"
                               class="text-blue-300 hover:text-blue-100 underline-offset-2 hover:underline">
                                {{ it.jurisdiction_name }}
                            </a>
                            <span class="text-gray-500 text-xs">
                                ADM{{ it.adm_level }}<span v-if="it.depth > 0"> · cascade depth {{ it.depth }}</span>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Review list -->
                <div v-if="autoscale.review_items?.length" class="mt-4 border-t border-gray-700/50 pt-3">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-amber-300 text-xs uppercase tracking-wide">
                            Needs attention ({{ (run.attention_count ?? run.review_count).toLocaleString() }})
                        </div>
                        <button
                            v-if="!runActive"
                            @click="resumeRun(true)"
                            :disabled="actionBusy"
                            class="text-xs px-2 py-1 rounded border border-gray-600 text-gray-300 hover:bg-gray-800 transition-colors"
                        >
                            Retry all review items
                        </button>
                    </div>
                    <div class="max-h-64 overflow-y-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="text-gray-500 uppercase">
                                <tr>
                                    <th class="py-1 pr-2">Legislature</th>
                                    <th class="py-1 pr-2">Kind</th>
                                    <th class="py-1 pr-2">Status</th>
                                    <th class="py-1">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-300">
                                <tr v-for="it in autoscale.review_items" :key="it.legislature_id" class="border-t border-gray-800">
                                    <td class="py-1.5 pr-2 whitespace-nowrap">
                                        <a :href="`/legislatures/${it.jurisdiction_slug}`" target="_blank"
                                           class="text-amber-300 hover:text-amber-100 underline-offset-2 hover:underline">
                                            {{ it.jurisdiction_name }}
                                        </a>
                                        <span class="text-gray-500"> ADM{{ it.adm_level }}</span>
                                    </td>
                                    <td class="py-1.5 pr-2">{{ it.kind === 'sweep' ? 'sweep' : 'single' }}</td>
                                    <td class="py-1.5 pr-2">{{ it.status }}</td>
                                    <td class="py-1.5 text-gray-400">{{ it.reason || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div v-else-if="autoscaleError" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">
                {{ autoscaleError }}
            </div>

            <!-- Apportionment summary -->
            <section
                v-if="summary"
                class="bg-emerald-900/20 border border-emerald-800/50 rounded-lg p-5 mb-6"
            >
                <div class="flex items-baseline gap-2 mb-3">
                    <span class="text-emerald-400 text-lg">✓</span>
                    <h2 class="text-emerald-200 font-semibold">Apportionment</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Legislatures sized</div>
                        <div class="text-white text-xl font-semibold mt-1">{{ summary.legislatures.toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Total seats apportioned</div>
                        <div class="text-white text-xl font-semibold mt-1">{{ summary.total_seats.toLocaleString() }}</div>
                    </div>
                    <div v-if="summary.largest">
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Largest legislature</div>
                        <div class="text-white text-xl font-semibold mt-1">
                            {{ summary.largest.name }}
                            <span class="text-gray-400 text-sm font-normal">
                                · {{ summary.largest.seats.toLocaleString() }} seats
                            </span>
                        </div>
                    </div>
                </div>
                <!-- WI-9: enumerate the legislatures. Each row links to its
                     own district mapper. -->
                <div v-if="summary.rows?.length" class="mt-4 border-t border-emerald-800/50 pt-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide mb-2">Legislatures</div>
                    <ul class="space-y-1 text-sm">
                        <li v-for="leg in summary.rows" :key="leg.slug" class="flex items-baseline gap-2">
                            <a :href="`/legislatures/${leg.slug}`" class="text-emerald-300 hover:text-emerald-100 underline-offset-2 hover:underline">
                                {{ leg.name }}
                            </a>
                            <span class="text-gray-400 text-xs tabular-nums">
                                {{ (leg.type_a_seats + leg.type_b_seats).toLocaleString() }} seats
                                <template v-if="leg.type_b_seats > 0">
                                    ({{ leg.type_a_seats.toLocaleString() }} A + {{ leg.type_b_seats.toLocaleString() }} B)
                                </template>
                            </span>
                        </li>
                        <li v-if="summary.legislatures > summary.rows.length" class="text-gray-500 text-xs italic">
                            … and {{ (summary.legislatures - summary.rows.length).toLocaleString() }} more
                        </li>
                    </ul>
                </div>
                <p class="text-gray-400 text-xs mt-4 italic">
                    Cube-root sizing applied per Taagepera's law — every jurisdiction, all the way down.
                </p>
            </section>

            <div v-else-if="summaryError" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">
                {{ summaryError }}
            </div>

            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 space-y-4">
                <div v-if="!mapperHref" class="bg-amber-900/30 border border-amber-800 rounded p-4 text-sm text-amber-200">
                    <div class="font-semibold mb-1">No root legislature found.</div>
                    <p>Step 1 must finish loading at least ADM0 data before districts can be drawn. Go back to the map-data step to verify.</p>
                </div>

                <div v-else>
                    <p class="text-gray-300 mb-4">
                        Spot-check any legislature in the interactive district mapper — the
                        <strong>{{ root_jurisdiction?.name ?? 'root' }}</strong> map is the natural first look.
                        Reading is always safe mid-run; a legislature currently being swept has its
                        autoseed controls locked until its sweep finishes.
                    </p>

                    <ul class="text-sm text-gray-400 space-y-2 mb-5 pl-4 list-disc">
                        <li>A banner at the top of the mapper will remind you you're in setup mode.</li>
                        <li>Review items above link straight into the affected legislature's mapper.</li>
                        <li>You can always come back here by visiting <code class="text-gray-300">/setup</code>.</li>
                    </ul>

                    <div class="flex items-center justify-between gap-4 pt-3 border-t border-gray-800">
                        <a href="/setup/step/2" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">
                            ← Back
                        </a>
                        <a
                            :href="mapperHref"
                            class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2 rounded-md font-semibold transition-colors inline-flex items-center gap-2"
                        >
                            Go to District Mapper →
                        </a>
                    </div>
                </div>
            </section>
    </div>
</template>
