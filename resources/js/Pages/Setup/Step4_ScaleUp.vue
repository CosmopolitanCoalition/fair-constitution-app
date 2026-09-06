<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import StageBars from '@/Components/Progress/StageBars.vue'
import { csrfFetch } from '@/lib/csrf'

// STEP 4 — SCALE UP INSTITUTIONS (Wave 6). The page triggers the engine
// (ruling done-flip-vs-pages A); the pump owns liveness; halt, resume and
// rollback are the operator's controls; the bars follow the ETL paradigm:
// bounded units, elapsed and a measured ETA, never a fabricated number.
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

const run    = computed(() => data.value?.run ?? null)
const ledger = computed(() => data.value?.ledger ?? {})
const world  = computed(() => data.value?.world ?? {})
const delta  = computed(() => data.value?.world_delta ?? {})
const stages = computed(() => data.value?.stages ?? [])
const lanes  = computed(() => data.value?.lanes ?? [])
const review = computed(() => data.value?.review ?? [])
const totalLeg = computed(() => data.value?.total_legislatures ?? 0)
const seeded   = computed(() => data.value?.seeded ?? 0)

// The world card shows this run's deltas, not the pre-existing stub rows.
const worldRows = computed(() => {
    const w = world.value, d = delta.value
    return [
        ['Executives', 'executives'], ['Courts', 'judiciaries'], ['Election boards', 'election_boards'],
        ['Public treasuries', 'treasuries'], ['Elections', 'elections'], ['Committees', 'committees'],
        ['Departments', 'departments'], ['Squares & halls', 'social_spaces'],
    ].map(([label, key]) => ({ label, cur: w[key] || 0, add: d[key] || 0 }))
})

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

async function poll() {
    try {
        const res = await fetch('/api/setup/wizard/step4/progress', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        if (res.ok) data.value = await res.json()
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
    if (r) notice.value = r.created ? 'Run started. The ledger seeds first; lanes follow.' : 'Adopted the live run.'
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
        ? 'Roll back EVERYTHING this run wrote: seats, acts, treasuries and the institution shells. The ledger returns to the start.'
        : 'Roll back the seats and the acts (elections, committees, departments, zero-balance treasuries). The shells stay.'
    if (!confirm(what + '\n\nThe run must be halted or done. Continue?')) return
    const r = await post('/api/setup/wizard/step4/rollback', { shells }, 'rollback')
    if (r) notice.value = 'Rolled back: ' + Object.entries(r.deleted || {}).filter(([, v]) => v > 0).map(([k, v]) => `${k} ${v.toLocaleString()}`).join(', ')
}
async function lockAndContinue() {
    const r = await post('/api/setup/wizard/step4/complete', {}, 'continue')
    if (r?.next) router.visit(r.next)
}

onMounted(() => { poll(); timer = setInterval(poll, POLL_MS) })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
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

        <!-- Summary -->
        <section class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Legislatures</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ n(summary.legislatures) }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Ledger rows</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ n(ledger.total) }}</div>
                <div class="text-gray-500 text-xs mt-1">{{ n(ledger.skipped) }} skipped by the zero rule</div>
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

        <!-- Run card -->
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
                        <span v-else>Run {{ run.id.slice(0, 8) }} · {{ run.status }}<span v-if="run.halt_requested && run.status !== 'halted'"> · halting</span></span>
                    </div>
                    <div class="text-gray-400 text-sm mt-1" v-if="run">
                        <span v-if="!run.ledger_seeded">Building the work-list: {{ n(seeded) }} / {{ n(totalLeg) }} legislatures enrolled (resumable, top-down by layer)</span>
                        <span v-else>
                            shells {{ n(ledger.shells_done) }} · units done {{ n(ledger.units_done) }} ·
                            running {{ n((ledger.shells_running ?? 0) + (ledger.units_running ?? 0)) }} ·
                            review {{ n(ledger.review) }}
                        </span>
                    </div>
                    <div class="text-gray-500 text-xs mt-1" v-if="run?.baseline?.elapsed_seconds != null">
                        Measured baseline: {{ fmtSecs(run.baseline.elapsed_seconds) }} for {{ n(run.baseline.units_done) }} units on {{ run.baseline.lanes }} lanes.
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

            <div v-if="run" class="mt-5">
                <StageBars :stages="stages" :poll-ms="POLL_MS" />
            </div>
        </section>

        <div v-if="error" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">{{ error }}</div>
        <div v-if="notice" class="bg-gray-800/60 border border-gray-700 rounded p-3 text-sm text-gray-200 mb-6">{{ notice }}</div>

        <!-- World counts: current, with this run's additions -->
        <section class="bg-gray-900 border border-gray-800 rounded-lg p-5 mb-6">
            <h2 class="text-white font-semibold mb-1">What exists now</h2>
            <p class="text-gray-500 text-xs mb-3">Total rows, and <span class="text-emerald-400">+ what this run added</span>. Executives and courts include rows from earlier builds.</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-2 text-sm">
                <div v-for="r in worldRows" :key="r.label" class="flex justify-between">
                    <span class="text-gray-400">{{ r.label }}</span>
                    <span class="text-gray-200 tabular-nums">{{ n(r.cur) }}<span v-if="r.add" class="text-emerald-400 ml-1">+{{ n(r.add) }}</span></span>
                </div>
            </div>
        </section>

        <!-- Lanes -->
        <section v-if="lanes.length" class="bg-gray-900 border border-gray-800 rounded-lg p-5 mb-6">
            <h2 class="text-white font-semibold mb-3">Lanes <span class="text-gray-500 font-normal text-sm">{{ lanes.length }} live · half top-down, half bottom-up</span></h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-1 text-xs font-mono">
                <div v-for="w in lanes" :key="w.id" class="flex items-center gap-2 text-gray-300">
                    <span class="w-2 h-2 rounded-full shrink-0" :class="w.claim_type ? 'bg-blue-400 animate-pulse' : 'bg-gray-600'"></span>
                    <span class="text-gray-500">{{ w.id }}</span>
                    <span class="text-gray-500">{{ w.lane }}</span>
                    <span class="truncate">{{ w.claim_label || 'idle' }}</span>
                    <span v-if="w.claim_secs != null" class="ml-auto text-gray-500 tabular-nums">{{ fmtSecs(w.claim_secs) }}</span>
                    <span class="text-gray-600 tabular-nums">· {{ w.claims_done }} done</span>
                </div>
            </div>
        </section>

        <!-- Review -->
        <section v-if="review.length" class="bg-amber-900/10 border border-amber-900/40 rounded-lg p-5 mb-6">
            <h2 class="text-amber-200 font-semibold mb-3">Review <span class="text-amber-400/70 font-normal text-sm">{{ n(ledger.review) }} rows · largest first</span></h2>
            <div class="space-y-1 text-xs">
                <div v-for="r in review" :key="r.legislature_id" class="flex gap-3 text-gray-300">
                    <a :href="`/legislatures/${r.slug || r.legislature_id}`" class="text-blue-300 hover:underline shrink-0">{{ r.name }} <span class="text-gray-500">ADM{{ r.adm_level }}</span></a>
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
