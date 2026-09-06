<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import StageBars from '@/Components/Progress/StageBars.vue'
import { csrfFetch } from '@/lib/csrf'

// STEP 4 — SCALE UP INSTITUTIONS (Wave 6, Step-3 parity 2026-09-06). The page
// triggers the engine; the pump owns liveness; halt / resume / rollback are
// the operator's controls. The display follows the Step 3 idiom: overall stage
// bars, segmented per-layer bars, a lane strip that breadcrumbs each lane's
// legislature with warn colours, a measured rate and honest ETA, a review
// drilldown. No fabricated numbers.
defineOptions({
    layout: (h, page) => h(AppShellV2, { variant: 'wide' }, () => page),
})

const props = defineProps({
    step:     { type: Number, required: true },
    settings: { type: Object, required: true },
    summary:  { type: Object, required: true },
    progress: { type: Object, default: null },
})

const POLL_MS = 3000
const data    = ref(props.progress)
const busy    = ref('')
const error   = ref('')
const notice  = ref('')
let timer = null
let clock = null

// A local 1s tick drives the lane elapsed clocks between the 3s polls.
const nowTick = ref(Date.now())

const run     = computed(() => data.value?.run ?? null)
const ledger  = computed(() => data.value?.ledger ?? {})
const stages  = computed(() => data.value?.stages ?? [])
const layers  = computed(() => data.value?.layers ?? [])
const lanes   = computed(() => data.value?.lanes ?? [])
const review  = computed(() => data.value?.review ?? [])
const totalLeg = computed(() => data.value?.total_legislatures ?? 0)
const seeded   = computed(() => data.value?.seeded ?? 0)

const runLive   = computed(() => run.value && ['queued', 'running'].includes(run.value.status))
const runHalted = computed(() => run.value && run.value.status === 'halted')
const runDone   = computed(() => run.value && run.value.status === 'done')
const canStart  = computed(() => !run.value || run.value.status === 'failed')
const locked    = computed(() => (props.settings.setup_step_completed ?? 0) >= 5)

function fmtSecs(s) {
    if (s == null) return '—'
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60
    return h > 0 ? `${h}h ${m}m` : m > 0 ? `${m}m ${sec}s` : `${sec}s`
}
function n(v) { return (v ?? 0).toLocaleString() }
function pct(a, b) { return b > 0 ? Math.min(100, Math.max(0, (a / b) * 100)) : 0 }

// ── Per-layer bars ──────────────────────────────────────────────────────────
function layerHeadline(l) {
    return `${n(l.seated)} / ${n(l.work)} founded`
        + (l.skipped ? ` · ${n(l.skipped)} skipped` : '')
}
function layerTitle(l) {
    return `${n(l.seated)} founded · ${n(l.shelled)} shelled (awaiting seats) · `
        + `${n(l.review)} review · ${n(l.pending)} pending · ${n(l.skipped)} skipped (zero rule)`
}

// ── Lane strip (Step 3 idiom) ────────────────────────────────────────────────
const warn = computed(() => run.value?.lane_warn_seconds ?? [30, 120])
function laneSecs(w) {
    // Server anchors claim_secs at poll time; the local tick advances it.
    if (w.claim_secs == null) return null
    return w.claim_secs + Math.floor((nowTick.value - pollStamp.value) / 1000)
}
const pollStamp = ref(Date.now())
function laneLevel(w) {
    const s = laneSecs(w)
    if (s == null || !w.claim_type) return 'normal'
    if (s >= warn.value[1]) return 'red'
    if (s >= warn.value[0]) return 'amber'
    return 'normal'
}
const laneTone = {
    normal: { dot: 'bg-blue-400', label: 'text-gray-200', clock: 'text-gray-500', bar: 'bg-blue-500', pulse: 'bg-blue-500/60' },
    amber:  { dot: 'bg-amber-400', label: 'text-amber-200', clock: 'text-amber-400', bar: 'bg-amber-500', pulse: 'bg-amber-500/60' },
    red:    { dot: 'bg-red-400',   label: 'text-red-200',   clock: 'text-red-400',   bar: 'bg-red-500',   pulse: 'bg-red-500/60' },
}
function admLabel(a) {
    return ['Planet', 'Countries', 'States / Provinces', 'Counties', 'Municipalities', 'Townships', 'Neighborhoods'][a] ?? (a != null ? `Level ${a}` : '')
}
const laneGroups = computed(() => {
    const g = { units: [], shells: [], idle: [] }
    for (const w of lanes.value) {
        if (w.claim_type === 'unit') g.units.push(w)
        else if (w.claim_type === 'shell_batch') g.shells.push(w)
        else g.idle.push(w)
    }
    return g
})
const laneSections = computed(() => [
    { key: 'units',  title: 'Founding legislatures', list: laneGroups.value.units },
    { key: 'shells', title: 'Building shells',        list: laneGroups.value.shells },
    { key: 'idle',   title: 'Between claims',         list: laneGroups.value.idle },
].filter(s => s.list.length))
function shellCount(w) {
    const m = (w.claim_label || '').match(/(\d[\d,]*)/)
    return m ? m[1] : ''
}

async function poll() {
    try {
        const res = await fetch('/api/setup/wizard/step4/progress', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        if (res.ok) { data.value = await res.json(); pollStamp.value = Date.now() }
    } catch (e) { /* the next tick retries */ }
}

async function post(url, body = {}, label = '') {
    busy.value = label
    error.value = ''
    notice.value = ''
    try {
        const res = await csrfFetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
        })
        const json = await res.json().catch(() => ({}))
        if (!res.ok || json.ok === false) {
            error.value = json.error || `Refused (HTTP ${res.status}).`
            return null
        }
        return json
    } catch (e) {
        error.value = String(e)
        return null
    } finally {
        busy.value = ''
        await poll()
    }
}

async function start() {
    const r = await post('/api/setup/wizard/step4/start', {}, 'start')
    if (r) notice.value = r.created ? 'Run started. The work-list seeds first; lanes follow.' : 'Adopted the live run.'
}
async function halt() {
    const r = await post('/api/setup/wizard/step4/halt', {}, 'halt')
    if (r) notice.value = 'Halt requested. Lanes stop at their next claim boundary; the pump reaps the rest within two minutes.'
}
async function resume(requeueReview = false) {
    const r = await post('/api/setup/wizard/step4/resume', { requeue_review: requeueReview }, 'resume')
    if (r) notice.value = requeueReview ? 'Resumed with the review rows requeued.' : 'Resumed.'
}
async function rollback(shells) {
    const what = shells
        ? 'Roll back EVERYTHING this run wrote: seats, acts, treasuries and the institution shells. The work-list returns to the start.'
        : 'Roll back the seats and the acts (elections, committees, departments, zero-balance treasuries). The shells stay.'
    if (!confirm(what + '\n\nThe run must be halted or done. Continue?')) return
    const r = await post('/api/setup/wizard/step4/rollback', { shells }, 'rollback')
    if (r) notice.value = 'Rolled back: ' + Object.entries(r.deleted || {}).filter(([, v]) => v > 0).map(([k, v]) => `${k} ${v.toLocaleString()}`).join(', ')
}
async function lockAndContinue() {
    const r = await post('/api/setup/wizard/step4/complete', {}, 'continue')
    if (r?.next) router.visit(r.next)
}

onMounted(() => {
    poll(); timer = setInterval(poll, POLL_MS)
    clock = setInterval(() => { nowTick.value = Date.now() }, 1000)
})
onBeforeUnmount(() => { if (timer) clearInterval(timer); if (clock) clearInterval(clock) })
</script>

<template>
    <div class="max-w-5xl mx-auto px-6 py-8 w-full">
        <SetupStepper :current="4" :completed="settings.setup_step_completed" :steps="settings.ladder" />

        <header class="mt-8 mb-6">
            <h1 class="text-3xl font-bold text-white mb-2">Scale Up Institutions</h1>
            <p class="text-gray-300 leading-relaxed">
                Every legislature receives its institutions. The shell set lands first in set-based batches:
                executive, court, election board, public square and halls, public treasury. Then one lane per
                legislature schedules its founding election and its races, and files the committees and
                departments as system acts. Halt, resume and roll back at any time. Continue locks the result.
            </p>
        </header>

        <!-- Summary tiles -->
        <section class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Legislatures</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ n(summary.legislatures) }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Founded</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ n(ledger.units_done) }}</div>
                <div class="text-gray-500 text-xs mt-1">{{ n(ledger.review) }} in review</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Rate</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ run?.rate_per_h != null ? n(run.rate_per_h) : '—' }}</div>
                <div class="text-gray-500 text-xs mt-1">{{ run?.rate_per_h != null ? run.rate_label : 'measuring' }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Elapsed</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ fmtSecs(run?.elapsed_s) }}</div>
                <div class="text-gray-500 text-xs mt-1">ETA {{ run?.eta_s != null ? fmtSecs(run.eta_s) : '— (measuring)' }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Lanes</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ run ? `${run.lanes} / ${run.pool}` : '—' }}</div>
                <div class="text-gray-500 text-xs mt-1">derived from this host</div>
            </div>
        </section>

        <!-- Run card: status, controls, overall stage bars, per-layer bars -->
        <section
            class="rounded-lg p-5 mb-6 border"
            :class="{
                'bg-blue-900/20 border-blue-800/50': runLive,
                'bg-amber-900/20 border-amber-800/50': runHalted,
                'bg-emerald-900/20 border-emerald-800/50': runDone,
                'bg-gray-900 border-gray-800': !run,
            }"
        >
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-white font-semibold">
                        <span v-if="!run">No Step 4 run yet</span>
                        <span v-else>Run {{ run.id.slice(0, 8) }} · {{ run.status }}<span v-if="run.phase && run.status === 'running'"> · {{ run.phase }}</span><span v-if="run.halt_requested && run.status !== 'halted'"> · halting</span></span>
                    </div>
                    <div class="text-gray-400 text-sm mt-1" v-if="run">
                        <span v-if="!run.ledger_seeded">Building the work-list: {{ n(seeded) }} / {{ n(totalLeg) }} legislatures enrolled (resumable, top-down by layer)</span>
                        <span v-else>
                            shells {{ n(ledger.shells_done) }} · founded {{ n(ledger.units_done) }} ·
                            running {{ n((ledger.shells_running ?? 0) + (ledger.units_running ?? 0)) }} ·
                            review {{ n(ledger.review) }}
                        </span>
                    </div>
                    <div class="text-gray-500 text-xs mt-1" v-if="run?.baseline?.elapsed_seconds != null">
                        Measured baseline: {{ fmtSecs(run.baseline.elapsed_seconds) }} for {{ n(run.baseline.units_done) }} legislatures on {{ run.baseline.lanes }} lanes.
                    </div>
                    <div class="text-amber-300 text-xs mt-1" v-if="data?.maps_running">The district maps are still being drawn. Step 4 starts when the map run is done.</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-if="canStart" type="button" :disabled="busy !== '' || data?.maps_running || locked" @click="start"
                        class="bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        {{ busy === 'start' ? 'Starting…' : 'Start Scale Up' }}
                    </button>
                    <button v-if="runLive" type="button" :disabled="busy !== '' || run.halt_requested" @click="halt"
                        class="bg-amber-700 hover:bg-amber-600 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        {{ busy === 'halt' ? 'Halting…' : 'Halt' }}
                    </button>
                    <button v-if="runHalted" type="button" :disabled="busy !== ''" @click="resume(false)"
                        class="bg-emerald-700 hover:bg-emerald-600 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        {{ busy === 'resume' ? 'Resuming…' : 'Resume' }}
                    </button>
                    <button v-if="(runHalted || runDone) && (ledger.review ?? 0) > 0" type="button" :disabled="busy !== ''" @click="resume(true)"
                        class="bg-emerald-800 hover:bg-emerald-700 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        Requeue review rows
                    </button>
                    <button v-if="runHalted || runDone" type="button" :disabled="busy !== '' || locked" @click="rollback(false)"
                        class="bg-red-800 hover:bg-red-700 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        {{ busy === 'rollback' ? 'Rolling back…' : 'Roll back seats and acts' }}
                    </button>
                    <button v-if="runHalted || runDone" type="button" :disabled="busy !== '' || locked" @click="rollback(true)"
                        class="bg-red-900 hover:bg-red-800 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        Roll back everything
                    </button>
                </div>
            </div>

            <!-- Overall stage bars -->
            <div v-if="run" class="mt-5">
                <StageBars :stages="stages" :poll-ms="POLL_MS" />
            </div>

            <!-- Segmented per-layer bars (the Step 3 idiom) -->
            <div v-if="layers.length" class="mt-4 border-t border-gray-700/50 pt-3">
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 mb-2">
                    <div class="text-gray-400 text-xs uppercase tracking-wide">By layer</div>
                    <div class="flex flex-wrap items-center gap-3 text-[11px] text-gray-400">
                        <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-emerald-500"></span>Founded</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-sky-500"></span>Shelled</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-amber-500"></span>Review</span>
                    </div>
                </div>
                <div class="space-y-2">
                    <div v-for="l in layers" :key="l.key">
                        <div class="flex justify-between text-xs mb-0.5"
                             :class="l.status === 'done' ? 'text-gray-500' : 'text-gray-400'">
                            <span>
                                <span v-if="l.status === 'done'" class="text-emerald-500 mr-1">✓</span>
                                <span v-else-if="l.status === 'running'" class="inline-block w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse mr-1"></span>
                                {{ l.label }}
                                <span v-if="l.review" class="text-amber-400 ml-1">· {{ n(l.review) }} review</span>
                            </span>
                            <span class="tabular-nums">{{ layerHeadline(l) }}</span>
                        </div>
                        <div class="h-1.5 bg-gray-800 rounded overflow-hidden flex" :title="layerTitle(l)">
                            <div class="h-full bg-emerald-500 transition-all duration-700" :style="{ width: pct(l.seated, l.work) + '%' }"></div>
                            <div class="h-full bg-sky-500 transition-all duration-700"     :style="{ width: pct(l.shelled, l.work) + '%' }"></div>
                            <div class="h-full bg-amber-500 transition-all duration-700"   :style="{ width: pct(l.review, l.work) + '%' }"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div v-if="error" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">{{ error }}</div>
        <div v-if="notice" class="bg-gray-800/60 border border-gray-700 rounded p-3 text-sm text-gray-200 mb-6">{{ notice }}</div>

        <!-- Lane strip: grouped, breadcrumbed, warn-coloured -->
        <section v-if="lanes.length" class="bg-gray-900 border border-gray-800 rounded-lg p-5 mb-6">
            <h2 class="text-white font-semibold mb-3">Lanes
                <span class="text-gray-500 font-normal text-sm">{{ lanes.length }} live / {{ run?.pool ?? '—' }} · half top-down, half bottom-up</span>
            </h2>
            <div class="space-y-3">
                <div v-for="grp in laneSections" :key="grp.key">
                    <div class="text-gray-500 text-[11px] uppercase tracking-wide mb-1">{{ grp.title }} <span class="text-gray-600">({{ grp.list.length }})</span></div>
                    <ul class="space-y-1.5">
                        <li v-for="w in grp.list" :key="w.id"
                            class="text-xs bg-gray-800/60 rounded px-2.5 py-2"
                            :class="{ 'ring-1 ring-amber-700/60': laneLevel(w) === 'amber', 'ring-1 ring-red-700/70': laneLevel(w) === 'red' }">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2 min-w-0">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0"
                                          :class="w.claim_type ? [laneTone[laneLevel(w)].dot, 'animate-pulse'] : 'bg-gray-600'" aria-hidden="true"></span>
                                    <!-- Unit lane: the legislature it is founding, linked, with its layer -->
                                    <span v-if="w.claim_type === 'unit' && w.leg_name" class="truncate min-w-0" :class="laneTone[laneLevel(w)].label">
                                        <a :href="`/legislatures/${w.leg_slug || ''}`" target="_blank" class="font-medium underline-offset-2 hover:underline">{{ w.leg_name }}</a>
                                        <span v-if="w.adm_level != null" class="text-gray-500 ml-1">{{ admLabel(w.adm_level) }}</span>
                                        <span class="text-gray-500"> · founding</span>
                                    </span>
                                    <!-- Shell lane: the batch -->
                                    <span v-else-if="w.claim_type === 'shell_batch'" class="font-medium truncate" :class="laneTone[laneLevel(w)].label">
                                        Shell batch<span v-if="shellCount(w)" class="text-gray-500"> · {{ shellCount(w) }} places</span>
                                    </span>
                                    <span v-else class="text-gray-500 italic">between claims</span>
                                </span>
                                <span class="flex items-center gap-2 tabular-nums shrink-0" :class="laneTone[laneLevel(w)].clock">
                                    <span v-if="w.claim_type">{{ fmtSecs(laneSecs(w)) }} on claim<span v-if="laneLevel(w) !== 'normal'"> ({{ laneLevel(w) }})</span></span>
                                    <span class="text-gray-600">· {{ n(w.claims_done) }} done</span>
                                    <span class="font-mono text-gray-600">{{ w.id }}</span>
                                </span>
                            </div>
                            <div class="h-1 bg-gray-900 rounded overflow-hidden mt-1.5">
                                <div v-if="w.claim_type" class="h-full w-1/4 rounded animate-pulse" :class="laneTone[laneLevel(w)].pulse"></div>
                                <div v-else class="h-full w-0"></div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Review drilldown -->
        <section v-if="review.length" class="bg-amber-900/10 border border-amber-900/40 rounded-lg p-5 mb-6">
            <h2 class="text-amber-200 font-semibold mb-3">Review <span class="text-amber-400/70 font-normal text-sm">{{ n(ledger.review) }} rows · largest first</span></h2>
            <div class="space-y-1 text-xs">
                <div v-for="r in review" :key="r.legislature_id" class="flex gap-3 text-gray-300">
                    <a :href="`/legislatures/${r.slug || r.legislature_id}`" target="_blank" class="text-blue-300 hover:underline shrink-0">{{ r.name }} <span class="text-gray-500">{{ admLabel(r.adm_level) }}</span></a>
                    <span class="text-gray-400 truncate">{{ r.reason }}</span>
                </div>
            </div>
        </section>

        <div class="flex justify-between pt-4 border-t border-gray-800 mt-4">
            <a href="/setup/step/3" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">← Back</a>
            <button
                type="button"
                :disabled="busy !== '' || (!runDone && !locked)"
                @click="lockAndContinue"
                class="bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700 text-white px-5 py-2 rounded-md font-semibold transition-colors"
                :title="runDone || locked ? 'Lock the scaled world and continue' : 'Continue opens when the run is done'"
            >
                {{ busy === 'continue' ? 'Locking…' : (locked ? 'Continue →' : 'Lock and Continue →') }}
            </button>
        </div>
    </div>
</template>
