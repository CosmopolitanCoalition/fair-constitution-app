<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import StageBars from '@/Components/Progress/StageBars.vue'
import { csrfFetch } from '@/lib/csrf'

const { t } = useI18n()

// STEP 5 — SIMULATE (Wave 7). The page triggers the sim engine; the pump owns
// liveness; halt / resume / roll back are the operator's controls. Same idiom
// as Step 4: header tiles, overall stage bars, segmented per-layer bars, a lane
// strip with warn colours, a measured windowed rate and honest ETA, a review
// drilldown. The numbers come from SimSnapshot (the same reader the /simworld
// console uses), so nothing here is fabricated or drifts from that surface.
defineOptions({
    layout: (h, page) => h(AppShellV2, { variant: 'wide' }, () => page),
})

const props = defineProps({
    step:            { type: Number, required: true },
    settings:        { type: Object, required: true },
    progress:        { type: Object, default: null },
    control_refusal: { type: String, default: null },
})

const POLL_MS = 3000
const data    = ref(props.progress)
const busy    = ref('')
const error   = ref('')
const notice  = ref('')
let timer = null
let clock = null

const nowTick   = ref(Date.now())
const pollStamp = ref(Date.now())

const run    = computed(() => data.value?.run ?? null)
const ledger = computed(() => data.value?.ledger ?? {})
const stages = computed(() => data.value?.stages ?? [])
const phasePlan = computed(() => data.value?.phase_plan ?? { total: 0, phases: [] })
const layers = computed(() => data.value?.layers ?? [])
const lanes  = computed(() => data.value?.lanes ?? [])
const review = computed(() => data.value?.review ?? [])
const timings = computed(() => data.value?.timings ?? [])
const world  = computed(() => data.value?.world ?? {})
const readiness = computed(() => data.value?.readiness ?? { run_done: false, verify_total: 0, verify_done: 0, verify_review: 0, pending: false, complete: false, unresolved: [] })

// ── Timing panel: where the time goes, and the gap between claims ────────────
// VISIBLE during Step 5 perf work (the diagnostic that shows which stage owns
// the run's time). Flip to false once the sim is tuned, as Step 4 did.
const SHOW_TIMINGS = true
const timingMax = computed(() => Math.max(1, ...timings.value.map(t => t.total_s || 0)))
const TIMING_LABELS = {
    'lane.between_claims': t('c_setup.step5_simulate.timing_between_claims', 'Between claims (idle + acquire)'),
    'lane.claim_next': t('c_setup.step5_simulate.timing_claim_next', 'Claim acquisition'),
    'stage.cohort_scope': t('c_setup.step5_simulate.timing_cohort', 'Cohorts (who lives where)'),
    'stage.identity_batch': t('c_setup.step5_simulate.timing_identity', 'Identities (minting people)'),
    'stage.election_scope': t('c_setup.step5_simulate.timing_election', 'Elections (fielding candidates)'),
    'stage.count_election': t('c_setup.step5_simulate.timing_count', 'Counting ballots'),
    'stage.seat_scope': t('c_setup.step5_simulate.timing_seat', 'Seating representatives'),
    'stage.governance_scope': t('c_setup.step5_simulate.timing_governance', 'Governance (committees, departments)'),
    'stage.judiciary_scope': t('c_setup.step5_simulate.timing_judiciary', 'Judiciary (courts, nominations)'),
    'stage.civics_scope': t('c_setup.step5_simulate.timing_civics', 'Civics (orgs, bills)'),
    'stage.training_scope': t('c_setup.step5_simulate.timing_training', 'Training (pre-train the fleet)'),
    'stage.stipend_scope': t('c_setup.step5_simulate.timing_stipend', 'Stipend (the money plane)'),
    'stage.verify_scope': t('c_setup.step5_simulate.timing_verify', 'Verify (the acceptance scan)'),
}
function timingLabel(p) { return TIMING_LABELS[p] ?? p }
function timingTone(p) {
    if (p === 'lane.between_claims') return 'text-amber-300'
    if (p.startsWith('stage.')) return 'text-gray-200 font-medium'
    return 'text-gray-400'
}
function timingBar(p) { return p === 'lane.between_claims' ? 'bg-amber-500/70' : 'bg-blue-500/70' }

const runLive   = computed(() => run.value && ['queued', 'running'].includes(run.value.status))
const runHalted = computed(() => run.value && run.value.status === 'halted')
const runDone   = computed(() => run.value && run.value.status === 'done')
const canStart  = computed(() => !run.value || ['failed', 'done'].includes(run.value.status))
const locked    = computed(() => (props.settings.setup_step_completed ?? 0) >= 6)
const refused   = computed(() => !!props.control_refusal)

// ── World-readiness guard (G1) ───────────────────────────────────────────────
// The Lock control opens only when the completion guard would pass: the run is
// done, the acceptance scan minted verify items, and none are in review. A
// forced finish with documented exclusions is a separate, explicit path.
const verifyPending  = computed(() => !!readiness.value.pending)
const verifyReview   = computed(() => (readiness.value.verify_review ?? 0) > 0)
const guardPasses    = computed(() => !!readiness.value.complete)
const canLock        = computed(() => locked.value || guardPasses.value)
const canForceFinish = computed(() => !locked.value && !!readiness.value.run_done && !verifyPending.value && verifyReview.value)

function fmtSecs(s) {
    if (s == null) return '—'
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60
    return h > 0 ? `${h}h ${m}m` : m > 0 ? `${m}m ${sec}s` : `${sec}s`
}
function n(v) { return localeFmt.number((v ?? 0)) }
function pct(a, b) { return b > 0 ? Math.min(100, Math.max(0, (a / b) * 100)) : 0 }

// ── Per-layer bars ───────────────────────────────────────────────────────────
function layerHeadline(l) {
    return l.review
        ? t('c_setup.step5_simulate.layer_headline_with_review', { done: n(l.done), total: n(l.total), review: n(l.review) })
        : t('c_setup.step5_simulate.layer_headline', { done: n(l.done), total: n(l.total) })
}
function layerTitle(l) {
    return t('c_setup.step5_simulate.layer_title', { done: n(l.done), running: n(l.running), review: n(l.review), pending: n(l.pending) })
}

// ── Lane strip ───────────────────────────────────────────────────────────────
const warn = computed(() => run.value?.lane_warn_seconds ?? [30, 120])
function laneSecs(w) {
    if (w.claim_secs == null) return null
    return w.claim_secs + Math.floor((nowTick.value - pollStamp.value) / 1000)
}
function laneLevel(w) {
    const s = laneSecs(w)
    if (s == null || !w.claim_type) return 'normal'
    if (s >= warn.value[1]) return 'red'
    if (s >= warn.value[0]) return 'amber'
    return 'normal'
}
const laneTone = {
    normal: { dot: 'bg-blue-400', label: 'text-gray-200', clock: 'text-gray-400', pulse: 'bg-blue-500/60' },
    amber:  { dot: 'bg-amber-400', label: 'text-amber-200', clock: 'text-amber-400', pulse: 'bg-amber-500/60' },
    red:    { dot: 'bg-red-400',   label: 'text-red-200',   clock: 'text-red-400',   pulse: 'bg-red-500/60' },
}
const KIND_LABELS = {
    profile_research: t('c_setup.step5_simulate.kind_profile_research', 'Researching localities'),
    cohort_scope: t('c_setup.step5_simulate.kind_cohort', 'Deciding who lives where'),
    identity_batch: t('c_setup.step5_simulate.kind_identity', 'Minting people'),
    election_scope: t('c_setup.step5_simulate.kind_election', 'Calling elections'),
    count_election: t('c_setup.step5_simulate.kind_count', 'Counting ballots'),
    seat_scope: t('c_setup.step5_simulate.kind_seat', 'Seating representatives'),
    governance_scope: t('c_setup.step5_simulate.kind_governance', 'Growing chambers'),
    judiciary_scope: t('c_setup.step5_simulate.kind_judiciary', 'Seating courts'),
    civics_scope: t('c_setup.step5_simulate.kind_civics', 'Modelling civic life'),
    training_scope: t('c_setup.step5_simulate.kind_training', 'Training the fleet'),
    stipend_scope: t('c_setup.step5_simulate.kind_stipend', 'Paying the civic stipend'),
    verify_scope: t('c_setup.step5_simulate.kind_verify', 'Verifying the world'),
}
function kindLabel(k) { return KIND_LABELS[k] ?? (k || t('c_setup.step5_simulate.between_claims', 'between claims')) }
function admLabel(a) {
    const labels = [
        t('c_setup.step5_simulate.adm_0', 'Planet'), t('c_setup.step5_simulate.adm_1', 'Countries'),
        t('c_setup.step5_simulate.adm_2', 'States / Provinces'), t('c_setup.step5_simulate.adm_3', 'Counties'),
        t('c_setup.step5_simulate.adm_4', 'Municipalities'), t('c_setup.step5_simulate.adm_5', 'Townships'),
        t('c_setup.step5_simulate.adm_6', 'Neighborhoods'),
    ]
    return labels[a] ?? (a != null ? t('c_setup.step5_simulate.adm_level', { level: a }) : '')
}
// One section per claim kind, idle lanes last.
const laneSections = computed(() => {
    const byKind = {}
    const idle = []
    for (const w of lanes.value) {
        if (!w.claim_type) { idle.push(w); continue }
        ;(byKind[w.claim_type] ??= []).push(w)
    }
    const sections = Object.entries(byKind).map(([kind, list]) => ({ key: kind, title: kindLabel(kind), list }))
    if (idle.length) sections.push({ key: 'idle', title: t('c_setup.step5_simulate.lane_section_idle', 'Between claims'), list: idle })
    return sections
})

async function poll() {
    try {
        const res = await fetch('/api/setup/wizard/step5/progress', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
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
            error.value = json.error || json.reason || t('c_setup.step5_simulate.err_refused', { status: res.status })
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

// ── Dependency-aware scope (operator 2026-09-06) ─────────────────────────────
// Pick WHAT to simulate. Prerequisites auto-include and dependents auto-clear,
// so a required aspect can never be skipped — the same closure the backend
// enforces. base (people + wallets) is always on and not shown.
const ASPECT_LABELS = {
    elections: t('c_setup.step5_simulate.aspect_elections', 'Elections & seating'),
    governance: t('c_setup.step5_simulate.aspect_governance', 'Governance & courts'),
    civic_life: t('c_setup.step5_simulate.aspect_civic_life', 'Civic life (orgs, CGCs, boards)'),
    training: t('c_setup.step5_simulate.aspect_training', 'Training'),
    money: t('c_setup.step5_simulate.aspect_money', 'Money (stipends)'),
}
const ASPECT_REQUIRES = {
    elections: [],
    governance: ['elections'],
    civic_life: ['governance'],
    training: ['elections'],
    money: ['elections'],
}
const aspects = ref({ elections: true, governance: true, civic_life: true, training: true, money: true })

function requiresOf(k) {
    const out = new Set(); const stack = [...(ASPECT_REQUIRES[k] || [])]
    while (stack.length) { const r = stack.pop(); if (!out.has(r)) { out.add(r); (ASPECT_REQUIRES[r] || []).forEach(x => stack.push(x)) } }
    return [...out]
}
function dependentsOf(k) {
    return Object.keys(ASPECT_REQUIRES).filter(a => requiresOf(a).includes(k))
}
function setAspect(k, val) {
    aspects.value[k] = val
    if (val) requiresOf(k).forEach(r => aspects.value[r] = true)
    else dependentsOf(k).forEach(d => aspects.value[d] = false)
}
const selectedAspects = computed(() => Object.keys(aspects.value).filter(k => aspects.value[k]))

async function start(resume = false) {
    const r = await post('/api/setup/wizard/step5/start', { resume, aspects: selectedAspects.value }, 'start')
    if (r) notice.value = r.resumed ? t('c_setup.step5_simulate.notice_resuming', 'Resuming the unfinished run — re-enumerating cleanly.') : t('c_setup.step5_simulate.notice_started', 'Run started. The work-list enumerates first; lanes follow within the minute.')
}
async function halt() {
    const r = await post('/api/setup/wizard/step5/halt', {}, 'halt')
    if (r) notice.value = t('c_setup.step5_simulate.notice_halt', 'Halt requested. Lanes stop at their next claim boundary; resume picks up where it stopped.')
}
async function resume() {
    const r = await post('/api/setup/wizard/step5/resume', {}, 'resume')
    if (r) notice.value = t('c_setup.step5_simulate.notice_resumed', 'Resumed. The pump re-seeds workers within the minute.')
}
async function rollback() {
    const msg = t('c_setup.step5_simulate.rollback_confirm', 'Roll back this SIMULATION run: clear its work-list and lanes so a fresh run can re-enumerate. The world it already produced (cohorts, people, seats, civic records) is LEFT IN PLACE, and Step 4\'s institutions and every map are untouched.\n\nThe run must be halted or done. Continue?')
    if (!confirm(msg)) return
    const r = await post('/api/setup/wizard/step5/rollback', {}, 'rollback')
    if (r) notice.value = t('c_setup.step5_simulate.notice_rolled_back', { list: Object.entries(r.deleted || {}).filter(([, v]) => v > 0).map(([k, v]) => `${k} ${localeFmt.number(v)}`).join(', ') })
}
async function lockAndContinue() {
    const r = await post('/api/setup/wizard/step5/complete', {}, 'continue')
    if (r?.next) router.visit(r.next)
}
async function finishWithExclusions() {
    const list = (readiness.value.unresolved || []).map(u => `• ${u.name}${u.gaps ? ' — ' + u.gaps : ''}`).join('\n')
    const total = readiness.value.verify_review ?? 0
    const shown = (readiness.value.unresolved || []).length
    const more = total > shown ? t('c_setup.step5_simulate.exclusions_more', { count: total - shown }) : ''
    const msg = t('c_setup.step5_simulate.exclusions_confirm', { total, list, more })
    if (!confirm(msg)) return
    const r = await post('/api/setup/wizard/step5/complete', { force: true }, 'continue')
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
        <Head :title="t('c_setup.step5_simulate.heading', 'Simulate')" />
        <SetupStepper :current="5" :completed="settings.setup_step_completed" :steps="settings.ladder" />

        <header class="mt-8 mb-6">
            <h1 class="text-3xl font-bold text-white mb-2">{{ t('c_setup.step5_simulate.heading', 'Simulate') }}</h1>
            <p class="text-gray-300 leading-relaxed">
                {{ t('c_setup.step5_simulate.intro', 'Step 4 built the institutions. Step 5 populates them: residents decided per place, people minted, the founding elections fielded and counted by the real engine, representatives seated, chambers grown, courts seated, and civic life modelled. Each stage runs the constitution\'s own forms, never a shortcut. Halt, resume and roll back at any time. Continue locks the result.') }}
            </p>
        </header>

        <!-- Refusal: a real world will not run the populate engine -->
        <div v-if="refused" class="bg-amber-900/20 border border-amber-800/50 rounded-lg p-4 text-sm text-amber-200 mb-6">
            {{ control_refusal }}
        </div>

        <!-- Summary tiles -->
        <section class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.tile_work_items', 'Work items') }}</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ n(ledger.total) }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.tile_done', 'Done') }}</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ n(ledger.done) }}</div>
                <div class="text-gray-400 text-xs mt-1">{{ t('c_setup.step5_simulate.in_review', { n: n(ledger.review) }) }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.tile_rate', 'Rate') }}</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ run?.rate_per_h != null ? n(run.rate_per_h) : '—' }}</div>
                <div class="text-gray-400 text-xs mt-1">{{ run?.rate_per_h != null ? run.rate_label : t('c_setup.step5_simulate.measuring', 'measuring') }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.tile_elapsed', 'Elapsed') }}</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ fmtSecs(run?.elapsed_s) }}</div>
                <div class="text-gray-400 text-xs mt-1">{{ t('c_setup.step5_simulate.eta', { eta: run?.eta_s != null ? fmtSecs(run.eta_s) : t('c_setup.step5_simulate.eta_measuring', '— (measuring)') }) }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.tile_lanes', 'Lanes') }}</div>
                <div class="text-white text-2xl font-semibold mt-1 tabular-nums">{{ run ? `${run.lanes} / ${run.pool}` : '—' }}</div>
                <div class="text-gray-400 text-xs mt-1">{{ t('c_setup.step5_simulate.derived_from_host', 'derived from this host') }}</div>
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
                        <span v-if="!run">{{ t('c_setup.step5_simulate.no_run', 'No simulation run yet') }}</span>
                        <span v-else>{{ t('c_setup.step5_simulate.run_label', 'Run') }} {{ run.id.slice(0, 8) }} · {{ run.status }}<span v-if="run.phase && run.status === 'running'"> · {{ run.phase }}</span><span v-if="run.halt_requested && run.status !== 'halted'"> · {{ t('c_setup.step5_simulate.halting', 'halting') }}</span></span>
                    </div>
                    <div class="text-gray-400 text-sm mt-1" v-if="run">
                        {{ t('c_setup.step5_simulate.ledger_summary', { done: n(ledger.done), running: n(ledger.running), pending: n(ledger.pending), review: n(ledger.review) }) }}
                    </div>
                    <div class="text-red-300 text-xs mt-1" v-if="run?.last_error">{{ run.last_error }}</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-if="canStart && !refused" type="button" :disabled="busy !== '' || locked" @click="start(false)"
                        class="bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        {{ busy === 'start' ? t('c_setup.step5_simulate.btn_starting', 'Starting…') : t('c_setup.step5_simulate.btn_start', 'Start Simulation') }}
                    </button>
                    <button v-if="runLive && !refused" type="button" :disabled="busy !== '' || run.halt_requested" @click="halt"
                        class="bg-amber-700 hover:bg-amber-600 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        {{ busy === 'halt' ? t('c_setup.step5_simulate.btn_halting', 'Halting…') : t('c_setup.step5_simulate.btn_halt', 'Halt') }}
                    </button>
                    <button v-if="runHalted && !refused" type="button" :disabled="busy !== ''" @click="resume"
                        class="bg-emerald-700 hover:bg-emerald-800 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        {{ busy === 'resume' ? t('c_setup.step5_simulate.btn_resuming', 'Resuming…') : t('c_setup.step5_simulate.btn_resume', 'Resume') }}
                    </button>
                    <button v-if="(runHalted || runDone) && !refused" type="button" :disabled="busy !== '' || locked" @click="rollback"
                        class="bg-red-800 hover:bg-red-700 disabled:bg-gray-700 text-white px-4 py-2 rounded-md font-semibold text-sm">
                        {{ busy === 'rollback' ? t('c_setup.step5_simulate.btn_rolling_back', 'Rolling back…') : t('c_setup.step5_simulate.btn_rollback', 'Roll back run') }}
                    </button>
                </div>
            </div>

            <!-- Dependency-aware scope: what to simulate (before a run starts) -->
            <div v-if="canStart && !refused && !locked" class="mt-4 border-t border-gray-700/50 pt-3">
                <div class="text-gray-400 text-xs uppercase tracking-wide mb-2">{{ t('c_setup.step5_simulate.scope_heading', 'Scope') }}
                    <span class="text-gray-400 normal-case">{{ t('c_setup.step5_simulate.scope_sub', '· pick what to simulate — prerequisites are pulled in automatically, so nothing a chosen aspect needs can be left out. People and wallets always run.') }}</span>
                </div>
                <div class="flex flex-wrap gap-3">
                    <label v-for="(label, key) in ASPECT_LABELS" :key="key"
                        class="flex items-center gap-2 text-sm px-2.5 py-1.5 rounded bg-gray-800/60 cursor-pointer select-none"
                        :class="aspects[key] ? 'text-gray-100 ring-1 ring-blue-700/50' : 'text-gray-400'">
                        <input type="checkbox" :checked="aspects[key]" @change="setAspect(key, $event.target.checked)"
                            class="accent-blue-500" />
                        {{ label }}
                    </label>
                </div>
            </div>

            <!-- Phase plan: the whole run in order, so the total scope is visible from the start -->
            <div v-if="run && phasePlan.phases.length" class="mt-5 border-t border-gray-700/50 pt-3">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.phases_heading', 'Phases') }}
                        <span class="text-gray-400 normal-case">{{ t('c_setup.step5_simulate.phases_sub', '· the full run in order') }}</span>
                    </div>
                    <div class="text-gray-400 text-xs tabular-nums">{{ t('c_setup.step5_simulate.phases_done', { done: phasePlan.phases.filter(p => p.status === 'done').length, total: phasePlan.total }) }}</div>
                </div>
                <!-- Accordion: the current phase expands to its OWN layer bars (0->100%,
                     no rewind); finished phases collapse to a checked row. -->
                <div class="space-y-1">
                    <div v-for="p in phasePlan.phases" :key="p.phase"
                         class="rounded"
                         :class="p.status === 'current' ? 'bg-blue-900/20 ring-1 ring-blue-700/40 px-2 py-2' : 'px-2 py-1'">
                        <div class="flex items-center gap-2 text-sm"
                             :class="p.status === 'done' ? 'text-emerald-300' : p.status === 'current' ? 'text-blue-100' : 'text-gray-400'">
                            <span class="tabular-nums text-xs text-gray-400 w-4 text-right">{{ p.n }}</span>
                            <span v-if="p.status === 'done'" class="text-emerald-400">✓</span>
                            <span v-else-if="p.status === 'current'" class="inline-block w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                            <span v-else class="inline-block w-1.5 h-1.5 rounded-full border border-gray-600"></span>
                            <span class="font-medium">{{ p.label }}</span>
                            <span v-if="p.status === 'current' && p.total" class="text-blue-300 tabular-nums text-xs">{{ n(p.done) }} / {{ n(p.total) }}</span>
                            <div v-if="p.status === 'current' && p.total" class="flex-1 h-1 bg-gray-800 rounded overflow-hidden ml-1" style="max-width:35%">
                                <div class="h-full bg-emerald-500 transition-all duration-700" :style="{ width: pct(p.done, p.total) + '%' }"></div>
                            </div>
                        </div>
                        <div v-if="p.status === 'current'" class="mt-2 pl-6 space-y-1.5">
                            <div v-if="!layers.length" class="text-gray-400 text-xs">{{ t('c_setup.step5_simulate.enumerating', 'Enumerating this phase\'s work-list…') }}</div>
                            <div v-for="l in layers" :key="l.key">
                                <div class="flex justify-between text-[11px] mb-0.5" :class="l.status === 'done' ? 'text-gray-400' : 'text-gray-400'">
                                    <span>
                                        <span v-if="l.status === 'done'" class="text-emerald-500 mr-1">✓</span>
                                        {{ l.label }}
                                        <span v-if="l.review" class="text-amber-400 ml-1">· {{ t('c_setup.step5_simulate.n_review', { n: n(l.review) }) }}</span>
                                    </span>
                                    <span class="tabular-nums">{{ layerHeadline(l) }}</span>
                                </div>
                                <div class="h-1.5 bg-gray-800 rounded overflow-hidden flex" :title="layerTitle(l)">
                                    <div class="h-full bg-emerald-500 transition-all duration-700" :style="{ width: pct(l.done, l.total) + '%' }"></div>
                                    <div class="h-full bg-sky-500 transition-all duration-700"     :style="{ width: pct(l.running, l.total) + '%' }"></div>
                                    <div class="h-full bg-amber-500 transition-all duration-700"   :style="{ width: pct(l.review, l.total) + '%' }"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Colour key for the phase and layer bars above -->
            <div v-if="run" class="mt-3 flex flex-wrap items-center gap-3 text-[11px] text-gray-400">
                <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-emerald-500"></span>{{ t('c_setup.step5_simulate.legend_done', 'Done') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-sky-500"></span>{{ t('c_setup.step5_simulate.legend_running', 'Running') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-amber-500"></span>{{ t('c_setup.step5_simulate.legend_review', 'Review') }}</span>
            </div>
        </section>

        <div v-if="error" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">{{ error }}</div>
        <div v-if="notice" class="bg-gray-800/60 border border-gray-700 rounded p-3 text-sm text-gray-200 mb-6">{{ notice }}</div>

        <!-- What the run has produced -->
        <section v-if="run" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.world_people', 'People') }}</div>
                <div class="text-white text-xl font-semibold mt-1 tabular-nums">{{ n(world.people) }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.world_chambers_governed', 'Chambers governed') }}</div>
                <div class="text-white text-xl font-semibold mt-1 tabular-nums">{{ n(world.chambers_governed) }} <span class="text-gray-400 text-sm">/ {{ n(world.chambers) }}</span></div>
                <div class="text-gray-400 text-xs mt-1">{{ t('c_setup.step5_simulate.world_awaiting_election', { n: n(world.chambers_awaiting_election) }) }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.world_cohorts', 'Cohorts') }}</div>
                <div class="text-white text-xl font-semibold mt-1 tabular-nums">{{ n(world.cohorts) }}</div>
                <div class="text-gray-400 text-xs mt-1">{{ t('c_setup.step5_simulate.world_electorate', { n: n(world.electorate_modelled) }) }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.world_residencies', 'Residencies') }}</div>
                <div class="text-white text-xl font-semibold mt-1 tabular-nums">{{ n(world.residencies) }}</div>
            </div>
        </section>

        <!-- Lane strip: grouped by kind, warn-coloured -->
        <section v-if="lanes.length" class="bg-gray-900 border border-gray-800 rounded-lg p-5 mb-6">
            <h2 class="text-white font-semibold mb-3">{{ t('c_setup.step5_simulate.lanes_heading', 'Lanes') }}
                <span class="text-gray-400 font-normal text-sm">{{ t('c_setup.step5_simulate.lanes_sub', { live: lanes.length, pool: run?.pool ?? '—' }) }}</span>
            </h2>
            <div class="space-y-3">
                <div v-for="grp in laneSections" :key="grp.key">
                    <div class="text-gray-400 text-[11px] uppercase tracking-wide mb-1">{{ grp.title }} <span class="text-gray-600">({{ grp.list.length }})</span></div>
                    <ul class="space-y-1.5">
                        <li v-for="w in grp.list" :key="w.id"
                            class="text-xs bg-gray-800/60 rounded px-2.5 py-2"
                            :class="{ 'ring-1 ring-amber-700/60': laneLevel(w) === 'amber', 'ring-1 ring-red-700/70': laneLevel(w) === 'red' }">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2 min-w-0">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0"
                                          :class="w.claim_type ? [laneTone[laneLevel(w)].dot, 'animate-pulse'] : 'bg-gray-600'" aria-hidden="true"></span>
                                    <span v-if="w.claim_type" class="truncate min-w-0" :class="laneTone[laneLevel(w)].label">
                                        {{ w.claim_label || kindLabel(w.claim_type) }}
                                    </span>
                                    <span v-else class="text-gray-400 italic">{{ t('c_setup.step5_simulate.between_claims', 'between claims') }}</span>
                                </span>
                                <span class="flex items-center gap-2 tabular-nums shrink-0" :class="laneTone[laneLevel(w)].clock">
                                    <span v-if="w.claim_type">{{ fmtSecs(laneSecs(w)) }} {{ t('c_setup.step5_simulate.on_claim', 'on claim') }}<span v-if="laneLevel(w) !== 'normal'"> ({{ laneLevel(w) }})</span></span>
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

        <!-- Timing: where the time goes, across all lanes -->
        <section v-if="SHOW_TIMINGS && timings.length" class="bg-gray-900 border border-gray-800 rounded-lg p-5 mb-6">
            <h2 class="text-white font-semibold mb-1">{{ t('c_setup.step5_simulate.timing_heading', 'Timing') }}
                <span class="text-gray-400 font-normal text-sm">{{ t('c_setup.step5_simulate.timing_sub', 'where the time goes · avg per part, total across all lanes') }}</span>
            </h2>
            <p class="text-gray-400 text-xs mb-3" v-html="t('c_setup.step5_simulate.timing_note', 'The bar is each part\'s share of total lane-seconds. Watch <span class=&quot;text-amber-300&quot;>Between claims</span>: a lane that sits idle is a lane not working. Compare a stage\'s avg before and after a change to prove it faster or slower.')"></p>
            <div class="space-y-1 text-xs overflow-x-auto" tabindex="0" role="region" :aria-label="t('c_setup.step5_simulate.timings_region', 'Timings (scrollable)')">
                <div v-for="tm in timings" :key="tm.part" class="flex items-center gap-3 min-w-[20rem]">
                    <span class="w-56 shrink-0 truncate" :class="timingTone(tm.part)">{{ timingLabel(tm.part) }}</span>
                    <span class="w-20 text-right tabular-nums text-gray-300">{{ t('c_setup.step5_simulate.ms_value', '{n} ms', { named: { n: tm.avg_ms } }) }}</span>
                    <span class="w-24 text-right tabular-nums text-gray-400 hidden md:inline">{{ t('c_setup.step5_simulate.max_ms', 'max {n} ms', { named: { n: tm.max_ms } }) }}</span>
                    <span class="w-20 text-right tabular-nums text-gray-400 hidden md:inline">{{ n(tm.count) }}×</span>
                    <div class="flex-1 h-2 bg-gray-800 rounded overflow-hidden">
                        <div class="h-full transition-all duration-700" :class="timingBar(tm.part)" :style="{ width: pct(tm.total_s, timingMax) + '%' }"></div>
                    </div>
                    <span class="w-16 text-right tabular-nums text-gray-400">{{ n(tm.total_s) }}s</span>
                </div>
            </div>
        </section>

        <!-- World-readiness rollup (G1): the acceptance scan's verdict -->
        <section v-if="run" class="bg-gray-900 border border-gray-800 rounded-lg p-5 mb-6">
            <h2 class="text-white font-semibold mb-3">{{ t('c_setup.step5_simulate.readiness_heading', 'World readiness') }}
                <span class="font-normal text-sm"
                      :class="guardPasses ? 'text-emerald-400/80' : (verifyPending ? 'text-amber-400/80' : (verifyReview ? 'text-amber-400/80' : 'text-gray-400'))">
                    <template v-if="guardPasses">{{ t('c_setup.step5_simulate.readiness_verified', { done: n(readiness.verify_done), total: n(readiness.verify_total) }) }}</template>
                    <template v-else-if="verifyPending">{{ t('c_setup.step5_simulate.readiness_pending', 'verification pending — run the acceptance scan before locking') }}</template>
                    <template v-else-if="verifyReview">{{ t('c_setup.step5_simulate.readiness_review', { n: n(readiness.verify_review) }) }}</template>
                    <template v-else-if="!readiness.run_done">{{ t('c_setup.step5_simulate.readiness_not_done', 'the run must finish before verification') }}</template>
                    <template v-else>{{ t('c_setup.step5_simulate.readiness_verified_count', { done: n(readiness.verify_done), total: n(readiness.verify_total) }) }}</template>
                </span>
            </h2>
            <div class="grid grid-cols-3 gap-3 text-sm mb-3">
                <div class="bg-gray-950/40 rounded p-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.verify_scopes', 'Verify scopes') }}</div>
                    <div class="text-white text-xl font-semibold tabular-nums">{{ n(readiness.verify_total) }}</div>
                </div>
                <div class="bg-gray-950/40 rounded p-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.verify_passed', 'Passed') }}</div>
                    <div class="text-emerald-300 text-xl font-semibold tabular-nums">{{ n(readiness.verify_done) }}</div>
                </div>
                <div class="bg-gray-950/40 rounded p-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide">{{ t('c_setup.step5_simulate.verify_unresolved', 'Unresolved') }}</div>
                    <div class="text-amber-300 text-xl font-semibold tabular-nums">{{ n(readiness.verify_review) }}</div>
                </div>
            </div>
            <p v-if="verifyPending" class="text-amber-200/80 text-xs">
                {{ t('c_setup.step5_simulate.verify_pending_note', 'The run finished but the acceptance scan never ran. It is bounded and resumable — run the verify phase, then lock. This state cannot be forced past.') }}
            </p>
            <div v-if="readiness.unresolved && readiness.unresolved.length" class="space-y-1 text-xs mt-1">
                <div v-for="(u, i) in readiness.unresolved" :key="i" class="flex gap-3 text-gray-300">
                    <a v-if="u.slug" :href="`/jurisdictions/${u.slug}`" target="_blank" class="text-blue-300 hover:underline shrink-0">{{ u.name }} <span class="text-gray-400">{{ admLabel(u.adm_level) }}</span></a>
                    <span v-else class="text-gray-300 shrink-0">{{ u.name }}</span>
                    <span class="text-gray-400 truncate">{{ u.gaps }}</span>
                </div>
            </div>
        </section>

        <!-- Review drilldown -->
        <section v-if="review.length" class="bg-amber-900/10 border border-amber-900/40 rounded-lg p-5 mb-6">
            <h2 class="text-amber-200 font-semibold mb-3">{{ t('c_setup.step5_simulate.review_heading', 'Review') }} <span class="text-amber-400/70 font-normal text-sm">{{ t('c_setup.step5_simulate.review_sub', { n: n(ledger.review) }) }}</span></h2>
            <div class="space-y-1 text-xs">
                <div v-for="(r, i) in review" :key="i" class="flex gap-3 text-gray-300">
                    <a v-if="r.slug" :href="`/legislatures/${r.slug}`" target="_blank" class="text-blue-300 hover:underline shrink-0">{{ r.jurisdiction }} <span class="text-gray-400">{{ admLabel(r.adm_level) }}</span></a>
                    <span v-else class="text-gray-300 shrink-0">{{ r.jurisdiction }}</span>
                    <span class="text-gray-400 shrink-0">{{ kindLabel(r.kind) }}</span>
                    <span class="text-gray-400 truncate">{{ r.reason }}</span>
                </div>
            </div>
        </section>

        <div class="flex justify-between items-center pt-4 border-t border-gray-800 mt-4">
            <a href="/setup/step/4" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">{{ t('c_setup.step5_simulate.back', '← Back') }}</a>
            <div class="flex items-center gap-3">
                <button
                    v-if="canForceFinish"
                    type="button"
                    :disabled="busy !== ''"
                    @click="finishWithExclusions"
                    class="border border-amber-700/60 text-amber-200 hover:bg-amber-900/20 disabled:opacity-50 px-4 py-2 rounded-md text-sm font-medium transition-colors"
                    :title="t('c_setup.step5_simulate.force_finish_title', 'Record the unresolved scopes into the completion notes and finish')"
                >
                    {{ busy === 'continue' ? t('c_setup.step5_simulate.btn_finishing', 'Finishing…') : t('c_setup.step5_simulate.btn_force_finish', 'Finish with documented exclusions') }}
                </button>
                <button
                    type="button"
                    :disabled="busy !== '' || !canLock"
                    @click="lockAndContinue"
                    class="bg-emerald-700 hover:bg-emerald-800 disabled:bg-gray-700 text-white px-5 py-2 rounded-md font-semibold transition-colors"
                    :title="canLock ? t('c_setup.step5_simulate.lock_title_ready', 'Lock the simulated world and continue') : (verifyPending ? t('c_setup.step5_simulate.lock_title_pending', 'Run the acceptance scan before locking') : (verifyReview ? t('c_setup.step5_simulate.lock_title_review', 'Resolve the unresolved scopes, or finish with documented exclusions') : t('c_setup.step5_simulate.lock_title_wait', 'Continue opens when the run is verified')))"
                >
                    {{ busy === 'continue' ? t('c_setup.step5_simulate.btn_locking', 'Locking…') : (locked ? t('c_setup.step5_simulate.btn_continue', 'Continue →') : t('c_setup.step5_simulate.btn_lock_continue', 'Lock and Continue →')) }}
                </button>
            </div>
        </div>
    </div>
</template>
