<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import { csrfFetch } from '@/lib/csrf'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    // ShellV2 (operator, 2026-08-04). Setup ran on the v1 shell with
    // `chrome: 'minimal'`, which is why it had NO bottom command bar and the
    // OLD dev controls: CmdBar and the Dev* panels are ShellV2 components, so
    // a v1 setup page could never receive either. Menus that cannot work yet
    // are locked by MenuNav while instance.setupComplete is false.
    layout: (h, page) => h(AppShellV2, { variant: 'wide' }, () => page),
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
// with the Step-2 contract: the poll is ALWAYS armed while the page is open
// (even with no run yet, or a halted one, so a run created or resumed
// elsewhere appears within a poll), and stops only on done/failed. Every
// action handler re-arms it.
//
// THE POLL CADENCE (fix 7, 2026-09-02): 2 s while the run works (queued,
// sizing, mapping, or no run yet), 10 s while it is halted or paused, none
// after done/failed. One request in flight at a time. A request aborts
// after 15 s. A hidden tab stops polling; the tab becoming visible again
// polls at once and restarts the chain.

const autoscale = ref(null)      // { run, layers, precompute, live_items, review_items }
const autoscaleError = ref('')
const actionBusy = ref(false)
const POLL_FAST_MS = 2000
const POLL_SLOW_MS = 10000
const POLL_ABORT_MS = 15000
let pollTimer = null             // the pending setTimeout of the poll chain
let pollArmed = false            // true while the page wants the chain running
let pollInFlight = false         // one request at a time
let pollAbort = null             // AbortController of the in-flight request
let summaryTick = 0

const run = computed(() => autoscale.value?.run ?? null)
const worldBuild = computed(() => autoscale.value?.world_build ?? null)
const layers = computed(() => autoscale.value?.layers ?? [])

// A1 (operator order 2026-08-29): the parents SIZING PASS as a real bar —
// live count / total with rate, ETA and elapsed computed from this page's
// own poll samples (honest measurement, never fabricated). Active while
// the pass is genuinely mid-walk; the row-count bar steps aside then.
const sizingSamples = ref([])   // [{t, v}] of run.sized_parents
const parentsPassActive = computed(() =>
    run.value && run.value.status === 'sizing'
    && (run.value.parents_total ?? 0) > 0
    && run.value.sized_parents > 0
    && run.value.sized_parents < run.value.parents_total)
const sizingRatePerMin = computed(() => {
    const s = sizingSamples.value
    if (s.length < 2) return null
    const a = s[0], b = s[s.length - 1]
    const dt = (b.t - a.t) / 1000
    if (dt < 15 || b.v <= a.v) return null
    return (b.v - a.v) / (dt / 60)
})
const sizingEtaSeconds = computed(() => {
    if (!parentsPassActive.value || !sizingRatePerMin.value) return null
    return Math.round((run.value.parents_total - run.value.sized_parents) / sizingRatePerMin.value * 60)
})
const sizingElapsed = computed(() => {
    const s = sizingSamples.value
    if (!s.length) return null
    return Math.round((Date.now() - s[0].t) / 1000)
})
function sampleSizing(r) {
    if (!r || r.status !== 'sizing' || !(r.sized_parents > 0)) { sizingSamples.value = []; return }
    const s = sizingSamples.value
    if (s.length && s[s.length - 1].v === r.sized_parents) return
    s.push({ t: Date.now(), v: r.sized_parents })
    if (s.length > 40) s.splice(0, s.length - 40)
}

// THE GEODATA TIMING GRAMMAR, imported (operator order 2026-08-29): every
// live bar carries measured rate, honest ETA, and elapsed — computed from
// this page's own poll samples per bar key, exactly the ingestion panel's
// discipline (an ETA appears only once a real rate exists; never fabricated).
const barSamples = ref({})
const nowTick = ref(Date.now())
setInterval(() => { nowTick.value = Date.now() }, 1000)
function trackBar(key, v) {
    if (v == null || v <= 0) return
    const all = barSamples.value
    const s = (all[key] ??= [])
    if (s.length && s[s.length - 1].v === v) return
    s.push({ t: Date.now(), v })
    if (s.length > 40) s.splice(0, s.length - 40)
}
function barTiming(key, done, total) {
    void nowTick.value
    const s = barSamples.value[key]
    if (!s || s.length < 2 || !done) return ''
    const a = s[0], b = s[s.length - 1]
    const dt = (b.t - a.t) / 1000
    if (dt < 15 || b.v <= a.v) return ''
    const perMin = (b.v - a.v) / (dt / 60)
    const elapsed = fmtEta(Math.round((Date.now() - a.t) / 1000))
    let out = ` · ${Math.round(perMin).toLocaleString()}/min · ${elapsed} elapsed`
    if (total && done < total && perMin > 0) {
        out += ` · ~${fmtEta(Math.round((total - done) / perMin * 60))} left`
    }
    return out
}
function liveClaimSecs(w) {
    if (w.claim_secs == null) return null
    // Anchor the lane's clock once per payload row; the 1 s tick moves it.
    if (!w._t0) w._t0 = Date.now() - w.claim_secs * 1000
    return Math.max(0, Math.round((Date.now() - w._t0) / 1000))
}
function workerElapsed(w) {
    void nowTick.value
    const s = liveClaimSecs(w)
    return s == null ? '' : fmtEta(s)
}
// LANE WARNINGS (operator ruling 2026-09-02): deadlines are warnings, not
// kills. Past the first threshold the lane turns amber, past the second
// red. The thresholds come from the payload (run.lane_warn_seconds); this
// page never hard-codes them.
const laneWarn = computed(() => {
    const t = run.value?.lane_warn_seconds
    return Array.isArray(t) && t.length >= 2 ? [Number(t[0]), Number(t[1])] : null
})
function laneLevel(w) {
    void nowTick.value
    const s = liveClaimSecs(w)
    if (s == null || !laneWarn.value) return 'normal'
    if (s >= laneWarn.value[1]) return 'red'
    if (s >= laneWarn.value[0]) return 'amber'
    return 'normal'
}
const laneTone = {
    normal: { label: 'text-gray-200',  clock: 'text-gray-500',  dot: 'bg-blue-400',  bar: 'bg-blue-500',  pulse: 'bg-blue-800' },
    amber:  { label: 'text-amber-300', clock: 'text-amber-400', dot: 'bg-amber-400', bar: 'bg-amber-500', pulse: 'bg-amber-700' },
    red:    { label: 'text-red-300',   clock: 'text-red-400',   dot: 'bg-red-400',   bar: 'bg-red-500',   pulse: 'bg-red-700' },
}
function laneTitle(w) {
    const t = laneWarn.value
    if (!t || w.claim_secs == null) return ''
    const base = `Claim open for ${workerElapsed(w)}. Amber after ${fmtEta(t[0])}, red after ${fmtEta(t[1])}.`
    const lvl = laneLevel(w)
    if (lvl === 'red') return `${base} Past the second warning. Kill parks the scope in review.`
    if (lvl === 'amber') return `${base} Past the first warning.`
    return base
}
// STABLE SLOTS (operator, 2026-08-29: "everything seems bouncing around"):
// each worker keeps a fixed row, sorted by its id — the label and clock
// change in place, the rows never reshuffle. A batch lane's bar fills with
// its real i/N progress parsed from the label; other claims pulse.
const workersStable = computed(() =>
    // Longest-running claim first (operator order 2026-08-30); the id
    // tiebreak keeps equal-age rows from swapping between polls.
    [...(autoscale.value?.workers_detail ?? [])].sort((a, b) =>
        (b.claim_secs ?? -1) - (a.claim_secs ?? -1) || String(a.id).localeCompare(String(b.id))))
// THE INGESTION GROUPING (operator, 2026-08-29: "look at the geodata
// notes"): lanes render under the work they advance, clustered by kind —
// the flat strip was unreadable. Batch lanes eat the two-cutter wall,
// cascade lanes hold the giants, assessment closes maps.
const laneGroups = computed(() => {
    const g = { batch: [], cascade: [], assess: [], other: [], idle: [] }
    for (const w of workersStable.value) {
        const l = w.claim_label ?? ''
        if (!l) g.idle.push(w)
        else if (l.startsWith('2-cut batch')) g.batch.push(w)
        else if (l.startsWith('assessing')) g.assess.push(w)
        else if (w.claim_type === 'scope' || l.includes('depth')) g.cascade.push(w)
        else g.other.push(w)
    }
    return g
})
function batchFill(w) {
    const m = /(\d+)\s*\/\s*(\d+)/.exec(w.claim_label ?? '')
    if (!m) return null
    return Math.min(100, Math.round(Number(m[1]) / Number(m[2]) * 100))
}
// IDLE-CAUSE NAMING (operator approval 2026-08-29): an idle slot names WHY
// from the run's own aggregates — never a bare "idle" when the cause is
// knowable. The smalls-never-stop law makes "only giants remain" the one
// honest long-idle state.
const idleCause = computed(() => {
    const r = run.value
    if (!r) return 'idle — next claim within seconds'
    const left = Math.max(0, (r.sweeps_total ?? 0) - (r.sweeps_done ?? 0))
    if (left === 0) return 'idle — pile drained, closing out'
    if (r.light_pending === false) {
        return `idle — only giants remain (heavy slots ${r.heavy_running ?? '?'}/${r.heavy_cap ?? '?'} busy)`
    }
    return 'idle — next claim within seconds'
})
// PHASE BREADCRUMB (operator approval 2026-08-29): the run's own stamps,
// each phase with its measured elapsed; the live phase ticks.
const phaseTrail = computed(() => {
    const r = run.value
    if (!r) return []
    void nowTick.value
    const t = (s) => (s ? Date.parse(s) : null)
    const stamps = [
        { key: 'queued',     at: t(r.created_at) },
        { key: 'sizing',     at: t(r.sizing_started_at) },
        { key: 'precompute', at: t(r.precompute_started_at) },
        { key: 'mapping',    at: t(r.mapping_started_at) },
        { key: 'done',       at: t(r.finished_at) },
    ].filter((p) => p.at !== null)
    return stamps.map((p, i) => {
        const end = i + 1 < stamps.length ? stamps[i + 1].at : (r.finished_at ? t(r.finished_at) : Date.now())
        const live = i === stamps.length - 1 && !r.finished_at
        return {
            key: p.key,
            live,
            elapsed: p.key === 'done' ? null : fmtEta(Math.max(0, Math.round((end - p.at) / 1000))),
        }
    })
})
const precompute = computed(() => autoscale.value?.precompute ?? null)
const runActive = computed(() => run.value && ['queued', 'sizing', 'mapping'].includes(run.value.status))
const precomputeOpen = computed(() =>
    precompute.value && (precompute.value.done + precompute.value.failed) < precompute.value.total)

function pollDelayMs() {
    const r = run.value
    const status = r?.status
    if (status === 'done' || status === 'failed') return null
    if (status === 'halted' || r?.paused_until) return POLL_SLOW_MS
    return POLL_FAST_MS
}

function scheduleNextPoll() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null }
    if (!pollArmed) return
    if (typeof document !== 'undefined' && document.hidden) return   // resumes on visibilitychange
    const delay = pollDelayMs()
    if (delay === null) { pollArmed = false; return }
    pollTimer = setTimeout(fetchAutoscale, delay)
}

async function fetchAutoscale() {
    if (pollInFlight) return
    pollInFlight = true
    const ctl = typeof AbortController !== 'undefined' ? new AbortController() : null
    pollAbort = ctl
    const abortTimer = ctl ? setTimeout(() => ctl.abort(), POLL_ABORT_MS) : null
    try {
        const res = await fetch('/api/setup/wizard/step3/autoscale-progress', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: ctl?.signal,
        })
        if (!res.ok) {
            autoscaleError.value = `Could not load autoscale progress (HTTP ${res.status}).`
            return
        }
        autoscaleError.value = ''
        const data = await res.json()
        const wasActive = runActive.value
        autoscale.value = data
        sampleSizing(autoscale.value?.run)
        {
            const r = autoscale.value?.run
            const p = autoscale.value?.precompute
            if (r) {
                trackBar('singles', r.singles_done)
                trackBar('sweeps', r.sweeps_done)
                // Scope-based rate (operator order 2026-08-30): one total,
                // every scope — leaves included, since a leaf IS one scope.
                trackBar('all_scopes', (r.singles_done ?? 0)
                    + (autoscale.value?.layers ?? []).reduce((s, l) => s + (l.scopes_done ?? 0), 0))
                trackBar('mint', r.maps_minted)
            }
            if (p) trackBar('precompute', p.done + p.failed)
            for (const l of (autoscale.value?.layers ?? [])) trackBar(`layer:${l.key}`, l.scopes_total ? l.scopes_done : l.done)
        }

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
        if (status === 'done' || status === 'failed') {
            stopPolling()
        }
    } catch (e) {
        autoscaleError.value = e?.name === 'AbortError'
            ? `Progress request aborted after ${POLL_ABORT_MS / 1000} s. The next poll retries.`
            : String(e)
    } finally {
        if (abortTimer) clearTimeout(abortTimer)
        pollAbort = null
        pollInFlight = false
        scheduleNextPoll()
    }
}

function startPolling() {
    pollArmed = true
    scheduleNextPoll()
}

function stopPolling() {
    pollArmed = false
    if (pollTimer) {
        clearTimeout(pollTimer)
        pollTimer = null
    }
}

function onVisibilityChange() {
    if (document.hidden) {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null }
        return
    }
    if (pollArmed && !pollInFlight) fetchAutoscale()
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

// "Rewind mapping" — the UI door to `autoscale:revert` (UI↔CLI parity). Only
// offered on a HALTED run (the command's own guard), and the confirm dialog is
// the deliberate-intent gate the CLI's --force represents. Deletes generated
// maps, keeps sizing + precompute + boards + the audit chain, re-mints fresh
// founding drafts. The run stays halted; Resume carries it forward.
async function rewindRun() {
    if (!confirm('Rewind mapping to the start?\n\nThis DELETES every autoscale-generated district map and re-mints fresh founding drafts. Sizing, the founding boards, and the precomputed adjacency are kept; adopted/operator maps are never touched. The run stays halted — press Resume when ready.')) return
    actionBusy.value = true
    try {
        const res = await csrfFetch('/api/setup/wizard/step3/autoscale-revert', { method: 'POST' })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            autoscaleError.value = data.error || `rewind failed (HTTP ${res.status})`
            return
        }
        await fetchAutoscale()
        startPolling()
    } finally {
        actionBusy.value = false
    }
}

// "Group Type B chambers" — the UI door to `type-b:district` (UI↔CLI parity).
// Groups flagged chambers' constituents into shared panels and clears
// type_b_needs_districting so their at-large Type B races can schedule. Same
// TypeBDistrictMapper service and operator gate as the CLI; a bounded batch so
// one click never sweeps the planet unattended — press again for the rest.
const typeBBusy = ref(false)
const typeBResult = ref(null)
async function groupTypeB() {
    if (!confirm('Group the flagged Type B chambers into shared panels?\n\nThis clears type_b_needs_districting so their at-large Type B races can schedule. Runs a bounded batch — press again for the rest.')) return
    typeBBusy.value = true
    typeBResult.value = null
    try {
        const res = await csrfFetch('/api/setup/wizard/step3/type-b-district', { method: 'POST' })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            autoscaleError.value = data.error || `Type B grouping failed (HTTP ${res.status})`
            return
        }
        typeBResult.value = data
        await fetchAutoscale()
    } finally {
        typeBBusy.value = false
    }
}

// ── Lane kill + auto-kill (operator ruling 2026-09-02) ───────────────────
// A kill is a request: the lane stops at its next boundary and the scope
// parks in review. Two clicks per kill (arm, then confirm) so one stray
// click never kills a lane; the arm lapses after a few seconds. Auto-kill
// is the unattended dial, stored on the run.
const KILL_ARM_MS = 6000
const killArmed = ref({})        // lease_id -> true after the first click
const killPending = ref({})      // lease_id -> true while the POST is in flight
const killSent = ref({})         // lease_id -> the claim label the kill was sent for
const killArmTimers = {}
function laneKey(w) { return w.lease_id ?? w.id }
function killState(w) {
    const k = laneKey(w)
    if (w.kill_requested || killSent.value[k] === (w.claim_label ?? '')) return 'requested'
    if (killPending.value[k]) return 'pending'
    if (killArmed.value[k]) return 'armed'
    return 'idle'
}
function disarmKill(k) {
    clearTimeout(killArmTimers[k])
    delete killArmTimers[k]
    if (killArmed.value[k]) {
        const next = { ...killArmed.value }
        delete next[k]
        killArmed.value = next
    }
}
async function killLane(w) {
    const k = laneKey(w)
    if (!killArmed.value[k]) {
        killArmed.value = { ...killArmed.value, [k]: true }
        clearTimeout(killArmTimers[k])
        killArmTimers[k] = setTimeout(() => disarmKill(k), KILL_ARM_MS)
        return
    }
    disarmKill(k)
    killPending.value = { ...killPending.value, [k]: true }
    try {
        const res = await csrfFetch(`/api/setup/wizard/step3/lanes/${encodeURIComponent(k)}/kill`, { method: 'POST' })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            autoscaleError.value = data.error || data.message || `Kill request failed (HTTP ${res.status}).`
            return
        }
        killSent.value = { ...killSent.value, [k]: w.claim_label ?? '' }
        await fetchAutoscale()
    } catch (e) {
        autoscaleError.value = String(e)
    } finally {
        const next = { ...killPending.value }
        delete next[k]
        killPending.value = next
    }
}

const autoKillOn = ref(false)
const autoKillMinutes = ref(null)
const autoKillBusy = ref(false)
const autoKillError = ref('')
// Default when the operator switches it on with no stored value: twice the
// red threshold, in whole minutes (derived from the payload, never typed).
const autoKillDefaultMinutes = computed(() => {
    const t = laneWarn.value
    return t ? Math.max(1, Math.ceil((t[1] * 2) / 60)) : 30
})
watch(() => run.value?.auto_kill_minutes, (m) => {
    if (autoKillBusy.value) return          // the POST in flight owns the value
    autoKillOn.value = m != null
    if (m != null) autoKillMinutes.value = m
}, { immediate: true })
async function saveAutoKill() {
    autoKillError.value = ''
    let minutes = null
    if (autoKillOn.value) {
        minutes = Math.round(Number(autoKillMinutes.value))
        if (!Number.isFinite(minutes) || minutes < 1) {
            minutes = autoKillDefaultMinutes.value
            autoKillMinutes.value = minutes
        }
        // The endpoint accepts 1..1440 (one day); clamp before the POST so
        // a typed 5000 never round-trips as a 422.
        if (minutes > 1440) {
            minutes = 1440
            autoKillMinutes.value = minutes
        }
    }
    autoKillBusy.value = true
    try {
        const res = await csrfFetch('/api/setup/wizard/step3/auto-kill', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ minutes }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            // A 422 carries Laravel's `message`, other failures carry `error`.
            autoKillError.value = data.error || data.message || `Auto-kill setting failed (HTTP ${res.status}).`
            const m = run.value?.auto_kill_minutes ?? null   // back to what the run holds
            autoKillOn.value = m != null
            if (m != null) autoKillMinutes.value = m
            return
        }
        const m = data.auto_kill_minutes ?? null
        autoKillOn.value = m != null
        if (m != null) autoKillMinutes.value = m
    } catch (e) {
        autoKillError.value = String(e)
    } finally {
        autoKillBusy.value = false
    }
}
function toggleAutoKill(on) {
    autoKillOn.value = on
    if (on && !(Number(autoKillMinutes.value) >= 1)) autoKillMinutes.value = autoKillDefaultMinutes.value
    saveAutoKill()
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
    // Rate/ETA tracks SCOPES when the layer has them (operator order
    // 2026-08-30): scope completions are the fluid unit, so the per-minute
    // rate and the "~left" estimate come from them.
    for (const l of data?.layers ?? []) {
        const v = l.units_total != null ? l.units_done : (l.scopes_total ? l.scopes_done : l.done)
        tweenTo(`layer:${l.key}`, v)
        trackBar(`layer:${l.key}`, v)
    }
    if (data?.precompute) tweenTo('precompute', data.precompute.done)
})

function fmtEta(seconds) {
    if (seconds == null) return '—'
    if (seconds < 90) return `${seconds}s`
    const h = Math.floor(seconds / 3600)
    const m = Math.round((seconds % 3600) / 60)
    if (h >= 48) return `${Math.floor(h / 24)}d ${h % 24}h`
    if (h >= 1) return `${h}h ${m}m`
    // The second hand survives the minute mark (operator order 2026-08-30):
    // "3m 42s", so the counter keeps visibly ticking.
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
}

function pct(done, total) {
    if (!total) return 0
    return Math.min(100, Math.round((done / total) * 1000) / 10)
}

function layerLabel(l) {
    // Collapsed level rows carry their canonical name from the backend
    // (Planet, Countries, States / Provinces, ...). Legacy split rows keep
    // the old form until the payload refreshes.
    if (l.label) return l.label
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
                : 'Phase B — drawing every founding district map (trivials first, then biggest scopes first)'
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
    document.addEventListener('visibilitychange', onVisibilityChange)
})

onBeforeUnmount(() => {
    stopPolling()
    document.removeEventListener('visibilitychange', onVisibilityChange)
    pollAbort?.abort()
    for (const k of Object.keys(killArmTimers)) clearTimeout(killArmTimers[k])
})
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
                        <!-- Rewind mapping — the UI door to `autoscale:revert`
                             (parity). Halted-state only, like Resume; the
                             confirm dialog is the deliberate-intent gate. -->
                        <button
                            v-if="run.status === 'halted'"
                            @click="rewindRun"
                            :disabled="actionBusy"
                            class="text-xs px-3 py-1.5 rounded border border-rose-700 text-rose-300 hover:bg-rose-900/40 transition-colors"
                            title="Delete generated maps and re-mint fresh founding drafts (sizing + precompute kept)"
                        >
                            Rewind mapping
                        </button>
                    </div>
                </div>

                <!-- Phase breadcrumb (operator approval 2026-08-29): the run's
                     own stamps, each phase with its measured elapsed; the live
                     phase ticks with the clock. -->
                <div v-if="phaseTrail.length" class="flex flex-wrap items-center gap-1 text-xs text-gray-400 mb-3">
                    <template v-for="(p, i) in phaseTrail" :key="p.key">
                        <span v-if="i > 0" class="text-gray-600">›</span>
                        <span :class="p.live ? 'text-blue-300' : 'text-gray-400'">
                            {{ p.key }}<span v-if="p.elapsed" class="text-gray-500"> {{ p.elapsed }}</span>
                            <span v-if="p.live" class="inline-block w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse ml-0.5 align-middle"></span>
                        </span>
                    </template>
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
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Sweep rate (scopes, 10 min)</div>
                        <div class="text-white text-lg font-semibold mt-1 tabular-nums">
                            {{ run.sweeps_per_hour != null && run.sweeps_per_hour > 0 ? `${run.sweeps_per_hour.toLocaleString()}/h` : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">ETA (scopes)</div>
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
                    <!-- THE SIZING PASS as a real bar (operator order
                         2026-08-29): live parents count over the true total,
                         with rate / ETA / elapsed measured from this page's
                         own polls. While it runs, the row-count bar below
                         steps aside — a full database does not mean a
                         finished pass, and showing 100% mid-walk was ruled
                         misinformation. -->
                    <div v-if="parentsPassActive">
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span>Sizing pass — parent legislatures (re-verifies every parent)</span>
                            <span class="tabular-nums">
                                {{ run.sized_parents.toLocaleString() }} / {{ run.parents_total.toLocaleString() }}
                                <span v-if="sizingRatePerMin"> · {{ Math.round(sizingRatePerMin).toLocaleString() }}/min</span>
                                <span> · ETA {{ fmtEta(sizingEtaSeconds) }}</span>
                                <span v-if="sizingElapsed != null"> · {{ fmtEta(sizingElapsed) }} elapsed</span>
                            </span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded overflow-hidden">
                            <div class="h-full bg-amber-500 transition-all" :style="{ width: pct(run.sized_parents, run.parents_total) + '%' }"></div>
                        </div>
                    </div>
                    <!-- Founding-map mint: the step between sizing and the
                         mapping flip. Bar appears the moment maps exist. -->
                    <div v-if="run.status === 'sizing' && run.maps_minted > 0 && run.maps_total">
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span>Founding maps minted (one per legislature)</span>
                            <span class="tabular-nums">{{ run.maps_minted.toLocaleString() }} / {{ run.maps_total.toLocaleString() }}{{ barTiming('mint', run.maps_minted, run.maps_total) }}</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded overflow-hidden">
                            <div class="h-full bg-sky-500 transition-all" :style="{ width: pct(run.maps_minted, run.maps_total) + '%' }"></div>
                        </div>
                    </div>
                    <div v-if="run.sized_live != null && run.sizing_total && !parentsPassActive">
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span>Legislature rows in database</span>
                            <span class="tabular-nums">{{ shown('sized_live', run.sized_live) }} / {{ run.sizing_total.toLocaleString() }}</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded overflow-hidden">
                            <div class="h-full bg-emerald-500 transition-all" :style="{ width: pct(run.sized_live, run.sizing_total) + '%' }"></div>
                        </div>
                    </div>
                    <!-- ONE TOTAL, ALL SCOPES (operator order 2026-08-30):
                         precompute retired to ingestion; leaf councils and
                         sweeps merged — a leaf IS one scope by definition,
                         so the single bar counts every scope on the planet
                         and moves at the true machine rate. -->
                    <div>
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span>Scopes (every district drawing, leaves included)</span>
                            <!-- Total counts every future scope too: an
                                 unmaterialized sweep map holds at least its
                                 own root scope, so each layer contributes
                                 max(scopes seen, jurisdictions) and the
                                 planet reads ~940k, growing as giants
                                 materialize their sub-scopes. -->
                            <span class="tabular-nums">{{ ((run.singles_done ?? 0) + layers.reduce((s, l) => s + (l.scopes_done ?? 0), 0)).toLocaleString() }} / {{ layers.reduce((s, l) => s + Math.max(l.scopes_total ?? 0, l.total ?? 0), 0).toLocaleString() }}{{ barTiming('all_scopes', (run.singles_done ?? 0) + layers.reduce((s, l) => s + (l.scopes_done ?? 0), 0), layers.reduce((s, l) => s + Math.max(l.scopes_total ?? 0, l.total ?? 0), 0)) }}</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded overflow-hidden">
                            <div class="h-full bg-blue-500 transition-all" :style="{ width: pct((run.singles_done ?? 0) + layers.reduce((s, l) => s + (l.scopes_done ?? 0), 0), layers.reduce((s, l) => s + Math.max(l.scopes_total ?? 0, l.total ?? 0), 0)) + '%' }"></div>
                        </div>
                    </div>
                </div>

                <!-- Per-ADM-layer bars (operator order 2026-09-02): one bar per
                     level, segmented by class in a FIXED visual order —
                     trivials | line-splits | composites — each segment filled
                     by that class's done count over the level's units; the
                     void on the right is what the level still owes. The
                     processing order differs (trivials, then composites, then
                     line-splits); the picture always fills left to right. -->
                <div v-if="layers.length" class="mt-4 border-t border-gray-700/50 pt-3">
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 mb-2">
                        <div class="text-gray-400 text-xs uppercase tracking-wide">By layer (biggest first)</div>
                        <div class="flex items-center gap-3 text-[11px] text-gray-400">
                            <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-teal-500"></span>Trivials</span>
                            <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-sky-500"></span>Line-splits</span>
                            <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-sm bg-violet-500"></span>Composites</span>
                        </div>
                    </div>
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
                                <span class="tabular-nums">
                                    <template v-if="l.units_total != null">{{ shown(`layer:${l.key}`, l.units_done) }} / {{ l.units_total.toLocaleString() }}{{ l.status === 'running' ? barTiming(`layer:${l.key}`, l.units_done, l.units_total) : '' }}</template>
                                    <template v-else-if="l.scopes_total">{{ shown(`layer:${l.key}`, l.scopes_done) }} / {{ l.scopes_total.toLocaleString() }} scopes · {{ l.done.toLocaleString() }} / {{ l.total.toLocaleString() }}{{ l.status === 'running' ? barTiming(`layer:${l.key}`, l.scopes_done, l.scopes_total) : '' }}</template>
                                    <template v-else>{{ shown(`layer:${l.key}`, l.done) }} / {{ l.total.toLocaleString() }}{{ l.status === 'running' ? barTiming(`layer:${l.key}`, l.done, l.total) : '' }}</template>
                                </span>
                            </div>
                            <div v-if="l.units_total != null" class="h-1.5 bg-gray-800 rounded overflow-hidden flex"
                                 :title="`${l.trivial_done.toLocaleString()} / ${l.trivial_total.toLocaleString()} trivials · ${l.line_done.toLocaleString()} / ${l.line_total.toLocaleString()} line-splits · ${l.comp_done.toLocaleString()} / ${l.comp_total.toLocaleString()} composites`">
                                <div class="h-full bg-teal-500 transition-all"   :style="{ width: pct(l.trivial_done, l.units_total) + '%' }"></div>
                                <div class="h-full bg-sky-500 transition-all"    :style="{ width: pct(l.line_done, l.units_total) + '%' }"></div>
                                <div class="h-full bg-violet-500 transition-all" :style="{ width: pct(l.comp_done, l.units_total) + '%' }"></div>
                            </div>
                            <div v-else class="h-1.5 bg-gray-800 rounded overflow-hidden">
                                <div class="h-full transition-all"
                                     :class="l.kind === 'single' ? 'bg-teal-500' : 'bg-blue-500'"
                                     :style="{ width: pct(l.scopes_total ? l.scopes_done : l.done, l.scopes_total || l.total) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Drift is always wrong (operator ruling 2026-07-26, 0e9eda0). -->
                <p v-if="run.drifted_done > 0" class="text-amber-300 text-xs mt-3">
                    {{ run.drifted_done.toLocaleString() }} completed maps seat a total that differs from their
                    legislature's apportioned seats (net {{ run.net_drift > 0 ? '+' : '' }}{{ run.net_drift.toLocaleString() }}).
                    Drift is a defect. The head distributes to the children, so a map's seats must sum to its
                    apportioned total. These maps need a redraw.
                </p>

                <p v-if="run.last_error" class="text-red-300 text-xs mt-3 font-mono break-all">
                    Last error: {{ run.last_error }}
                </p>

                <!-- THE WORKER STRIP: one honest line per live worker — what
                     each one holds RIGHT NOW (fast sweeps blink through the
                     scope list below; this never lies about the pool). -->
                <!-- Workers as live bars (operator order 2026-08-29: the
                     geodata idiom — each lane is a bar naming its claim with
                     a ticking clock, never a hex-id listing; the id survives
                     as a small suffix). No per-claim totals exist for these
                     units, so the fill is the ingestion panel's honest
                     working-pulse sliver. -->
                <!-- LANE WARNINGS + AUTO-KILL (operator ruling 2026-09-02):
                     deadlines are warnings. A lane turns amber past the
                     first threshold and red past the second; the thresholds
                     come from the payload. Auto-kill is the unattended
                     dial: a lane past N minutes on one claim is killed and
                     its scope parks in review. Stored on the run. -->
                <div v-if="run.status !== 'done' && run.status !== 'failed'" class="mt-4 border-t border-gray-700/50 pt-3">
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs">
                        <!-- One label per control: the label owns the
                             checkbox only; the minutes input carries its
                             own accessible name. -->
                        <div class="flex items-center gap-2 text-gray-300 select-none">
                            <label class="flex items-center gap-2">
                                <input type="checkbox"
                                       class="rounded border-gray-600 bg-gray-800"
                                       :checked="autoKillOn"
                                       :disabled="autoKillBusy"
                                       @change="toggleAutoKill($event.target.checked)" />
                                <span>Auto-kill a lane after</span>
                            </label>
                            <input type="number" min="1" max="1440" step="1"
                                   aria-label="Auto-kill minutes on one claim"
                                   class="w-16 rounded bg-gray-800 border border-gray-700 px-1.5 py-0.5 text-gray-100 tabular-nums disabled:opacity-50"
                                   v-model.number="autoKillMinutes"
                                   :placeholder="autoKillDefaultMinutes"
                                   :disabled="!autoKillOn || autoKillBusy"
                                   @change="saveAutoKill" />
                            <span>min on one claim</span>
                            <span v-if="autoKillBusy" class="text-gray-500">saving</span>
                            <span v-else-if="run.auto_kill_minutes != null" class="text-emerald-400">on, {{ run.auto_kill_minutes }} min</span>
                            <span v-else class="text-gray-500">off</span>
                        </div>
                        <span v-if="laneWarn" class="text-gray-500">
                            Lane warning: amber after {{ fmtEta(laneWarn[0]) }}, red after {{ fmtEta(laneWarn[1]) }}. A killed scope parks in review.
                        </span>
                    </div>
                    <p v-if="autoKillError" class="text-red-300 text-xs mt-1">{{ autoKillError }}</p>
                </div>

                <div v-if="autoscale.workers_detail?.length" class="mt-4 border-t border-gray-700/50 pt-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide mb-2">
                        Workers ({{ autoscale.workers_detail.length }})
                    </div>
                    <div class="space-y-3">
                        <div v-for="grp in [
                                { key: 'batch',   title: 'Two-cutter wall — batch lanes', list: laneGroups.batch },
                                { key: 'cascade', title: 'Giant cascades — one scope per lane', list: laneGroups.cascade },
                                { key: 'assess',  title: 'Assessment — closing finished maps', list: laneGroups.assess },
                                { key: 'other',   title: 'Other work', list: laneGroups.other },
                                { key: 'idle',    title: 'Idle lanes', list: laneGroups.idle },
                             ].filter(x => x.list.length)" :key="grp.key">
                            <div class="text-gray-500 text-[11px] uppercase tracking-wide mb-1">{{ grp.title }} ({{ grp.list.length }})</div>
                    <ul class="space-y-1.5">
                        <!-- Lane color by claim age against run.lane_warn_seconds
                             (normal, amber, red). Kill is two clicks: arm,
                             then confirm; "kill requested" once the lane
                             holds the flag. -->
                        <li v-for="w in grp.list" :key="w.id"
                            class="text-xs bg-gray-800/60 rounded px-2.5 py-2"
                            :class="{ 'ring-1 ring-amber-700/60': laneLevel(w) === 'amber', 'ring-1 ring-red-700/70': laneLevel(w) === 'red' }"
                            :title="laneTitle(w)">
                            <div class="flex items-center justify-between mb-1">
                                <span class="flex items-center gap-2 min-w-0">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0"
                                          :class="w.claim_label ? [laneTone[laneLevel(w)].dot, 'animate-pulse'] : 'bg-gray-600'" aria-hidden="true" />
                                    <span v-if="w.claim_label" class="font-medium truncate" :class="laneTone[laneLevel(w)].label">{{ w.claim_label }}</span>
                                    <span v-else class="text-gray-500 italic">{{ idleCause }}</span>
                                </span>
                                <span class="flex items-center gap-2 tabular-nums shrink-0 ml-3" :class="laneTone[laneLevel(w)].clock">
                                    <template v-if="w.claim_label">
                                        <span>{{ workerElapsed(w) }} on claim<span v-if="laneLevel(w) !== 'normal'"> ({{ laneLevel(w) }} warning)</span></span>
                                        <span v-if="killState(w) === 'requested'" class="text-red-300 italic">kill requested</span>
                                        <button v-else
                                                type="button"
                                                @click="killLane(w)"
                                                :disabled="killState(w) === 'pending' || run.status === 'done' || run.status === 'failed'"
                                                class="px-2 py-0.5 rounded border transition-colors disabled:opacity-50"
                                                :class="killState(w) === 'armed'
                                                    ? 'border-red-500 bg-red-900/50 text-red-100'
                                                    : 'border-gray-600 text-gray-300 hover:border-red-600 hover:text-red-200'"
                                                :title="killState(w) === 'armed'
                                                    ? 'Click again to kill this lane. The scope parks in review.'
                                                    : 'Kill this lane at its next boundary. Two clicks.'">
                                            {{ killState(w) === 'pending' ? 'Killing' : (killState(w) === 'armed' ? 'Confirm kill' : 'Kill') }}
                                        </button>
                                        <span>·</span>
                                    </template>
                                    <span class="font-mono text-gray-500">{{ w.id }}</span>
                                </span>
                            </div>
                            <div class="h-1.5 bg-gray-900 rounded overflow-hidden">
                                <div v-if="batchFill(w) !== null"
                                     class="h-full rounded transition-all duration-700"
                                     :class="laneTone[laneLevel(w)].bar"
                                     :style="{ width: batchFill(w) + '%' }" />
                                <div v-else-if="w.claim_label" class="h-full w-1/4 rounded animate-pulse" :class="laneTone[laneLevel(w)].pulse" />
                                <div v-else class="h-full w-0" />
                            </div>
                        </li>
                    </ul>
                        </div>
                    </div>
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

                <!-- Drifted-done drilldown (operator order 2026-08-29): every
                     completed map whose NET seat total misses the budget,
                     clickable straight into its mapper like the review list.
                     Pure bonus-lift maps net to zero and never appear here. -->
                <div v-if="autoscale.drifted_items?.length" class="mt-4 border-t border-gray-700/50 pt-3">
                    <div class="text-rose-300 text-xs uppercase tracking-wide mb-2">
                        Completed with drift ({{ (run.drifted_done ?? autoscale.drifted_items.length).toLocaleString() }})
                    </div>
                    <div class="max-h-64 overflow-y-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="text-gray-500 uppercase">
                                <tr>
                                    <th class="py-1 pr-2">Legislature</th>
                                    <th class="py-1 pr-2">Expected</th>
                                    <th class="py-1 pr-2">Seated</th>
                                    <th class="py-1">Net drift</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-300">
                                <tr v-for="it in autoscale.drifted_items" :key="it.legislature_id" class="border-t border-gray-800">
                                    <td class="py-1.5 pr-2 whitespace-nowrap">
                                        <a :href="it.map_id ? `/legislatures/${it.jurisdiction_slug}/districts?map=${it.map_id}` : `/legislatures/${it.jurisdiction_slug}`"
                                           target="_blank"
                                           class="text-rose-300 hover:text-rose-100 underline-offset-2 hover:underline">
                                            {{ it.jurisdiction_name }}
                                        </a>
                                        <span class="text-gray-500"> ADM{{ it.adm_level }}</span>
                                    </td>
                                    <td class="py-1.5 pr-2">{{ it.seats_expected }}</td>
                                    <td class="py-1.5 pr-2">{{ it.seats_seated }}</td>
                                    <td class="py-1.5" :class="it.drift > 0 ? 'text-amber-300' : 'text-rose-300'">
                                        {{ it.drift > 0 ? '+' + it.drift : it.drift }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div v-else-if="autoscaleError" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">
                {{ autoscaleError }}
            </div>

            <!-- Phase 2 — the world build (no run yet): the same numbers the
                 accept gate verifies, live. -->
            <section
                v-else-if="worldBuild"
                class="rounded-lg p-5 mb-6 border bg-gray-900/60 border-gray-800"
            >
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h2 class="font-semibold text-white">World build</h2>
                    <span
                        :class="worldBuild.status === 'complete' ? 'text-emerald-400' : 'text-blue-300'"
                        class="text-xs uppercase tracking-wide"
                    >{{ worldBuild.status }}</span>
                </div>
                <div v-if="worldBuild.report" class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                    <div class="bg-gray-800/60 rounded p-3">
                        <div class="text-gray-400 text-xs uppercase mb-1">Apportionment</div>
                        <div class="text-white">{{ worldBuild.report.apportionment.done.toLocaleString() }} / {{ worldBuild.report.apportionment.total.toLocaleString() }}</div>
                        <div v-if="worldBuild.report.apportionment.refusals > 0" class="text-amber-300 text-xs mt-1">{{ worldBuild.report.apportionment.refusals }} gate refusals</div>
                    </div>
                    <div class="bg-gray-800/60 rounded p-3">
                        <div class="text-gray-400 text-xs uppercase mb-1">Borders precomputed</div>
                        <div class="text-white">{{ (worldBuild.report.adjacency.total - worldBuild.report.adjacency.open).toLocaleString() }} / {{ worldBuild.report.adjacency.total.toLocaleString() }}</div>
                    </div>
                    <div class="bg-gray-800/60 rounded p-3">
                        <div class="text-gray-400 text-xs uppercase mb-1">Founding maps</div>
                        <div class="text-white">{{ worldBuild.report.maps.unstamped === 0 ? 'all stamped' : worldBuild.report.maps.unstamped.toLocaleString() + ' unstamped' }}</div>
                    </div>
                    <div class="bg-gray-800/60 rounded p-3">
                        <div class="text-gray-400 text-xs uppercase mb-1">Legislatures</div>
                        <div class="text-white">{{ worldBuild.report.legislatures.missing_headers === 0 ? 'all covered' : worldBuild.report.legislatures.missing_headers.toLocaleString() + ' uncovered' }}</div>
                    </div>
                    <div class="bg-gray-800/60 rounded p-3">
                        <div class="text-gray-400 text-xs uppercase mb-1">Block keys</div>
                        <div class="text-white">{{ worldBuild.report.block_keys_missing === 0 ? 'stamped' : worldBuild.report.block_keys_missing.toLocaleString() + ' missing' }}</div>
                    </div>
                    <div class="bg-gray-800/60 rounded p-3">
                        <div class="text-gray-400 text-xs uppercase mb-1">Bootstrap board</div>
                        <div class="text-white">{{ worldBuild.report.board ? 'seated' : 'missing' }}</div>
                    </div>
                </div>
                <p v-if="worldBuild.status === 'complete'" class="text-emerald-300 text-sm mt-3">
                    Phase 2 is complete. Accepting the map data starts the drawing immediately.
                </p>
            </section>

            <!-- Type B districting — the UI door to `type-b:district` (parity).
                 Shows whenever chambers are flagged type_b_needs_districting;
                 the operator groups their constituents into shared panels so the
                 at-large Type B races can schedule. -->
            <section
                v-if="(autoscale?.type_b_flagged ?? 0) > 0 || typeBResult"
                class="rounded-lg p-5 mb-6 border bg-indigo-900/20 border-indigo-800/50"
            >
                <div class="flex items-center justify-between gap-3 mb-2">
                    <h2 class="font-semibold text-indigo-200">Type B districting</h2>
                    <button
                        v-if="(autoscale?.type_b_flagged ?? 0) > 0"
                        @click="groupTypeB"
                        :disabled="typeBBusy"
                        class="text-xs px-3 py-1.5 rounded border border-indigo-600 text-indigo-200 hover:bg-indigo-900/50 transition-colors disabled:opacity-50"
                        title="Group flagged chambers' constituents into shared panels and clear the districting flag"
                    >
                        {{ typeBBusy ? 'Grouping…' : 'Group Type B chambers' }}
                    </button>
                </div>
                <p class="text-sm text-indigo-100/80">
                    <span class="tabular-nums font-semibold">{{ (autoscale?.type_b_flagged ?? 0).toLocaleString() }}</span>
                    chamber(s) still flagged — Type B seats exceed the Type A total, so their constituents
                    must group into shared panels before the at-large race can elect. Equal, compact panels;
                    no geometry is cut.
                </p>
                <p v-if="typeBResult" class="text-xs text-indigo-200/70 mt-2 tabular-nums">
                    Last run: grouped {{ typeBResult.grouped.toLocaleString() }} ({{ typeBResult.seats.toLocaleString() }} seats),
                    {{ typeBResult.remaining.toLocaleString() }} remaining<span v-if="typeBResult.undercount"> · {{ typeBResult.undercount }} undercount</span><span v-if="typeBResult.failures"> · {{ typeBResult.failures }} failed</span>.
                </p>
            </section>

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
                        <li>The whole world is browsable while maps draw — and whatever
                            legislature you visit <strong>jumps the queue</strong>: its map
                            draws next.</li>
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
