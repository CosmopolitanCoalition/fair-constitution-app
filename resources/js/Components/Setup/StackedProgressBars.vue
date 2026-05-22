<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'

// ── Phase P.1.1: number-tween animation ────────────────────────────────────
// The wizard polls bars.json at 2 s and the backend throttles disk writes at
// 4 Hz. The displayed number would normally jump in step-changes at the
// polling boundary. We tween it instead: each time a bar's `current` changes,
// the displayed value animates from its current visible value to the new
// target over ~1.4 s with an ease-out curve. Several rapid updates compose
// naturally — a new target mid-animation re-starts from the current visible
// position, so the tween never visibly snaps.
const _tweened = ref({})    // key → currently-displayed (animated) value
const _animState = {}       // key → { startTs, startVal, targetVal, frameId, durationMs }
const _intervalState = {}   // key → { lastUpdateTs, intervalEma } for adaptive duration
const TWEEN_DURATION_DEFAULT_MS = 1400
const TWEEN_DURATION_MIN_MS = 200
const TWEEN_DURATION_MAX_MS = 5000
const EMA_ALPHA = 0.35       // weight on the most-recent observed interval

function _easeOutCubic(t) {
    return 1 - Math.pow(1 - t, 3)
}

// P.1.2: compute the next tween's duration to match the observed cadence
// of incoming data updates. If updates arrive every 600ms, the tween
// finishes in ~600ms so the counter reaches its target right as the next
// update lands. Result: a continuous integer-rolling sensation, not a
// fast-then-stall stutter.
function _adaptiveDuration(key) {
    const st = _intervalState[key]
    if (!st || !st.intervalEma) return TWEEN_DURATION_DEFAULT_MS
    // Shave 10% so the tween reliably FINISHES before the next update,
    // never overshooting into the next batch's start.
    const d = st.intervalEma * 0.9
    return Math.max(TWEEN_DURATION_MIN_MS, Math.min(TWEEN_DURATION_MAX_MS, d))
}

function _recordUpdateInterval(key) {
    const now = performance.now()
    let st = _intervalState[key]
    if (!st) {
        st = { lastUpdateTs: now, intervalEma: null }
        _intervalState[key] = st
        return
    }
    const observed = now - st.lastUpdateTs
    st.lastUpdateTs = now
    if (observed < 10) return  // ignore micro-bursts (multiple updates in same tick)
    if (st.intervalEma === null) {
        st.intervalEma = observed
    } else {
        st.intervalEma = (1 - EMA_ALPHA) * st.intervalEma + EMA_ALPHA * observed
    }
}

function _animateKey(key) {
    const s = _animState[key]
    if (!s) return
    const now = performance.now()
    const elapsed = now - s.startTs
    const progress = Math.min(elapsed / s.durationMs, 1)
    const eased = _easeOutCubic(progress)
    const v = Math.round(s.startVal + (s.targetVal - s.startVal) * eased)
    _tweened.value = { ..._tweened.value, [key]: v }
    if (progress < 1) {
        s.frameId = requestAnimationFrame(() => _animateKey(key))
    } else {
        s.frameId = null
        // Snap to final target to avoid rounding drift
        _tweened.value = { ..._tweened.value, [key]: s.targetVal }
    }
}

function _retargetTween(key, newTarget, status) {
    const visible = _tweened.value[key]
    if (visible === newTarget) return
    if (visible === undefined) {
        // First time seeing this bar. If it's currently running or just
        // completed (so the operator hasn't yet seen its progress), start
        // the tween from 0 so the visible bar animates IN. Otherwise (a
        // pending bar with target 0, or some other terminal state we
        // missed mid-run), snap to target without animation.
        if (status === 'running' || status === 'done') {
            _tweened.value = { ..._tweened.value, [key]: 0 }
        } else {
            _tweened.value = { ..._tweened.value, [key]: newTarget }
            return
        }
    }
    const visibleNow = _tweened.value[key] ?? 0
    if (visibleNow === newTarget) return
    // P.1.2: record this update's arrival to update the adaptive cadence
    // EMA, then pick a tween duration that matches the observed pace so
    // the counter reaches the new target right as the next update lands.
    _recordUpdateInterval(key)
    const durationMs = _adaptiveDuration(key)
    const existing = _animState[key]
    if (existing?.frameId) cancelAnimationFrame(existing.frameId)
    _animState[key] = {
        startTs:    performance.now(),
        startVal:   visibleNow,
        targetVal:  newTarget,
        durationMs: durationMs,
        frameId:    null,
    }
    _animState[key].frameId = requestAnimationFrame(() => _animateKey(key))
}

function tweened(b) {
    // Animated display value for this bar. Falls back to raw current
    // until the watcher has had a chance to seed the tween dict.
    return _tweened.value[b.key] ?? b.current ?? 0
}

function tweenedPct(b) {
    if (!b?.total) return 0
    return Math.min(100, Math.max(0, Math.round((tweened(b) / b.total) * 100)))
}

onBeforeUnmount(() => {
    for (const s of Object.values(_animState)) {
        if (s?.frameId) cancelAnimationFrame(s.frameId)
    }
})

/**
 * StackedProgressBars — Phase P.1 replacement for the heartbeat-driven
 * "currently processing X" reset-timer card.
 *
 * Reads /etl/control/bars.json (passed in via the `bars` prop from
 * SetupController::mapDataProgress) and renders three vertical stacks:
 *
 *   1. Boundaries (geoBoundaries) — one bar per ADM level
 *   2. Cleanup — synthesise + post-pass orphan resolution bars
 *   3. Population (WorldPop) — "X / Y countries done" + current country's
 *      load + per-ADM bars
 *
 * Each bar shows X / Y (Z%), elapsed (since started_at), and ETA derived
 * from observed rate. Completed bars stay visible with elapsed-time stamp.
 */

const props = defineProps({
    bars:      { type: Object, default: null },
    current:   { type: Object, default: null }, // P.1.1 — drives active-bar sub-phase
    lifecycle: { type: String, default: 'idle' }, // P.1.2 — hides "in progress" when failed/done
})

// P.1.2: section headers should only say "in progress" while the run is
// actually running. After halt/fail/complete, the bars are static and the
// label would otherwise stay green forever.
const isRunning = computed(() => props.lifecycle === 'running')

// ── Helpers ────────────────────────────────────────────────────────────────

function pct(b) {
    if (!b || !b.total || b.total <= 0) return 0
    return Math.min(100, Math.max(0, Math.round((b.current / b.total) * 100)))
}

function fmtNum(n) {
    if (n === null || n === undefined) return ''
    return n.toLocaleString()
}

function fmtDuration(seconds) {
    if (!seconds || seconds < 0 || !Number.isFinite(seconds)) return '—'
    const s = Math.round(seconds)
    if (s < 60) return `${s}s`
    if (s < 3600) {
        const m = Math.floor(s / 60)
        const r = s % 60
        return r ? `${m}m ${r}s` : `${m}m`
    }
    const h = Math.floor(s / 3600)
    const m = Math.floor((s % 3600) / 60)
    return m ? `${h}h ${m}m` : `${h}h`
}

function elapsedSeconds(b) {
    if (!b?.started_at) return null
    const start = Date.parse(b.started_at)
    if (Number.isNaN(start)) return null
    // P.1.2: when the supervisor paused the run, bars get a `paused_at`
    // timestamp. Clip the elapsed end there so the timer freezes for the
    // duration of the pause. On resume the supervisor pushes `started_at`
    // forward by the pause delta and clears `paused_at`, so this branch
    // becomes inactive again.
    const end = b.completed_at ? Date.parse(b.completed_at)
              : b.paused_at    ? Date.parse(b.paused_at)
              :                  Date.now()
    return (end - start) / 1000
}

function etaSeconds(b) {
    if (!b || b.status === 'done' || !b.total || !b.current) return null
    // P.1.2: ETA is meaningless while paused — the rate denominator
    // would be a frozen elapsed and the result misleading.
    if (b.paused_at) return null
    const elapsed = elapsedSeconds(b)
    if (!elapsed || elapsed < 1) return null
    const rate = b.current / elapsed
    if (rate <= 0) return null
    return (b.total - b.current) / rate
}

function statusColor(b) {
    if (!b) return 'bg-gray-700'
    if (b.status === 'done')    return 'bg-emerald-600'
    if (b.status === 'running') return 'bg-blue-500'
    return 'bg-gray-700'   // pending / unknown
}

function trackColor(b) {
    if (!b) return 'bg-gray-800'
    if (b.status === 'done')    return 'bg-emerald-950'
    if (b.status === 'running') return 'bg-blue-950'
    return 'bg-gray-950/50'   // pending: muted, lower contrast than running
}

// P.1.1: pending bars get dimmer text so the operator can scan and tell at a
// glance which steps haven't started yet without reading the status word.
function labelColor(b) {
    if (!b || b.status === 'pending') return 'text-gray-500'
    return 'text-gray-200'
}

// ── Derived ────────────────────────────────────────────────────────────────

const phase             = computed(() => props.bars?.phase || null)
const gbBars            = computed(() => props.bars?.geoboundaries_bars || [])
const wpSummary         = computed(() => props.bars?.worldpop_country_summary || null)
const wpCountryBars     = computed(() => props.bars?.worldpop_current_country_bars || [])
// Phase T.3 — cleanup bars (topological raster fallback, pixel-attribution
// correction). Routed via heartbeat keys `cleanup:*` from the Python ETL.
const cleanupBars       = computed(() => props.bars?.cleanup_bars || [])

// P.1.2: structured sub-progress for the active country — progress_current
// of progress_total. These MUST be declared before the watch below references
// `activeProgressCurrent` in its source array, or JavaScript's TDZ rules will
// throw ReferenceError when the script setup runs.
const activeProgressCurrent = computed(() => {
    const n = props.current?.progress_current
    return typeof n === 'number' ? n : null
})
const activeProgressTotal = computed(() => {
    const n = props.current?.progress_total
    return typeof n === 'number' && n > 0 ? n : null
})

// Phase P.1.1: re-target the tween for every bar whose `current` changes.
// Watches geoboundaries bars + per-country worldpop bars + the worldpop
// summary "X / Y countries" virtual bar (synthesized from wpSummary).
// P.1.2: also tween the current.progress_current (sub-line "X of Y").
watch([gbBars, wpCountryBars, wpSummary, cleanupBars, activeProgressCurrent], () => {
    for (const b of [...(gbBars.value || []), ...(wpCountryBars.value || []), ...(cleanupBars.value || [])]) {
        if (!b?.key) continue
        const target = b.status === 'done' ? (b.total || b.current || 0) : (b.current || 0)
        _retargetTween(b.key, target, b.status)
    }
    const s = wpSummary.value
    if (s && typeof s.done === 'number' && typeof s.total === 'number') {
        const status = s.done >= s.total ? 'done' : 'running'
        _retargetTween('wp:summary', s.done, status)
    }
    const c = activeProgressCurrent.value
    if (c != null) {
        _retargetTween('current:progress', c, 'running')
    }
}, { deep: true, immediate: true })

// P.1.1 — overall elapsed timers.
// Boundaries: from earliest gb bar's started_at to latest gb bar's
//   completed_at (or now if any still running).
// Population: from earliest wp/pop bar's started_at to latest's completed
//   (or now). Surfaced as a "total: Xh Ym" line in each section header.
const nowTick = ref(Date.now())
let _nowTickTimer = setInterval(() => { nowTick.value = Date.now() }, 1000)
onBeforeUnmount(() => { if (_nowTickTimer) clearInterval(_nowTickTimer) })

function _phaseElapsed(barsArr, alsoIncludeStartedAt = null) {
    const starts = []
    let latestEnd = 0
    let anyRunning = false
    for (const b of barsArr || []) {
        if (b?.started_at) {
            const t = Date.parse(b.started_at)
            if (!Number.isNaN(t)) starts.push(t)
        }
        if (b?.completed_at) {
            const t = Date.parse(b.completed_at)
            if (!Number.isNaN(t) && t > latestEnd) latestEnd = t
        }
        if (b?.status === 'running') anyRunning = true
    }
    if (alsoIncludeStartedAt) {
        const t = Date.parse(alsoIncludeStartedAt)
        if (!Number.isNaN(t)) starts.push(t)
    }
    if (!starts.length) return null
    const start = Math.min(...starts)
    // P.1.2: while paused, use the supervisor-stamped `_paused_at` as the
    // wall-clock end so the phase total freezes alongside individual bars.
    const globalPausedAt = props.bars?._paused_at
        ? Date.parse(props.bars._paused_at)
        : null
    const wallNow = globalPausedAt && !Number.isNaN(globalPausedAt)
        ? globalPausedAt
        : nowTick.value
    const end = anyRunning ? wallNow : (latestEnd || wallNow)
    return Math.max(0, (end - start) / 1000)
}

const gbElapsed = computed(() => _phaseElapsed(gbBars.value))
const wpElapsed = computed(() => _phaseElapsed(
    wpCountryBars.value,
    wpSummary.value?.started_at || null,
))
// Phase T.3 — cleanup phase elapsed clock. Spans across all cleanup_bars
// (topo_fallback + t3_correction) so the operator sees one rolling timer
// for the whole post-Phase-2 cleanup queue.
const cleanupElapsed = computed(() => _phaseElapsed(cleanupBars.value))

// Phase P.1.1: active sub-progress label (e.g. "Municipality processed 1,200")
// from current.json. Surfaced under the active ADM bar so the operator sees
// intra-file progress when one country's file is huge (e.g. CHN ADM3 with
// thousands of municipalities) and the bar's headline number can't move.
const activeSubPhase = computed(() => props.current?.sub_phase || null)
const activeIso      = computed(() => props.current?.iso_code  || null)

// P.1.2: structured sub-progress for the active country — drives the
// tweened number + percentage display under the active bar. Falls back to
// the unstructured sub_phase label when the backend doesn't supply both
// fields (e.g. legacy emitters). The two refs (`activeProgressCurrent` /
// `activeProgressTotal`) themselves are declared above — before the watcher
// references them — to avoid TDZ. Only the derived computeds live here.
const activeProgressPct = computed(() => {
    const c = _tweened.value['current:progress'] ?? activeProgressCurrent.value
    const t = activeProgressTotal.value
    if (c == null || t == null || t === 0) return null
    return Math.min(100, Math.max(0, Math.round((c / t) * 100)))
})
const activeProgressUnit = computed(() => {
    // Re-use the active ADM bar's `unit` so the sub-line reads
    // "Counties 200 of 1,234 (16%)" — same noun as the parent bar.
    const k = props.bars?.active_key
    if (!k) return null
    const allBars = [
        ...(props.bars?.geoboundaries_bars || []),
        ...(props.bars?.worldpop_current_country_bars || []),
    ]
    const b = allBars.find(x => x.key === k)
    return b?.unit || null
})

const wpCountryPct      = computed(() => {
    const s = wpSummary.value
    if (!s || !s.total) return 0
    return Math.min(100, Math.round((s.done / s.total) * 100))
})

// P.1.1: tweened percentage for the worldpop summary bar so it animates
// alongside its tweened numeric counter.
const wpSummaryPctTweened = computed(() => {
    const s = wpSummary.value
    if (!s || !s.total) return 0
    const t = _tweened.value['wp:summary'] ?? s.done
    return Math.min(100, Math.max(0, Math.round((t / s.total) * 100)))
})

const hasAny = computed(() =>
    gbBars.value.length > 0
    || wpSummary.value !== null
    || wpCountryBars.value.length > 0
    || cleanupBars.value.length > 0
)
</script>

<template>
    <div v-if="hasAny" class="space-y-5">
        <!-- ── Boundaries (geoBoundaries) ─────────────────────────────── -->
        <section v-if="gbBars.length" class="space-y-2">
            <header class="flex items-baseline justify-between text-xs text-gray-400 uppercase tracking-wider gap-3">
                <span>Boundaries</span>
                <div class="flex items-baseline gap-3 normal-case tracking-normal">
                    <span v-if="gbElapsed !== null" class="text-gray-500 text-[11px] tabular-nums">
                        total {{ fmtDuration(gbElapsed) }}
                    </span>
                    <span v-if="phase === 'geoboundaries' && isRunning" class="text-blue-400">
                        in progress
                    </span>
                </div>
            </header>
            <div class="space-y-1.5">
                <div v-for="b in gbBars" :key="b.key"
                     class="rounded border px-3 py-2"
                     :class="[trackColor(b), b.status === 'pending' ? 'border-gray-800/60 opacity-70' : 'border-gray-800']">
                    <div class="flex items-baseline justify-between mb-1 gap-2">
                        <div class="text-sm truncate" :class="labelColor(b)">{{ b.label }}</div>
                        <div class="text-xs tabular-nums shrink-0"
                             :class="b.status === 'pending' ? 'text-gray-600' : 'text-gray-400'">
                            <template v-if="b.status === 'pending'">
                                {{ fmtNum(b.total) }} {{ b.unit || 'features' }} expected
                            </template>
                            <template v-else>
                                <!-- P.1.1: tweened current — animates smoothly between data
                                     updates so a 25-feature jump on the backend reads as a
                                     ~1.4 s rolling count on the screen.
                                     P.1.2: bar.unit ("counties", "townships", etc.) replaces
                                     the generic word "features" so the count reads as the
                                     plural of the unit actually being counted. -->
                                {{ fmtNum(tweened(b)) }} / {{ fmtNum(b.total) }} {{ b.unit || 'features' }}
                                <span class="text-gray-500">({{ tweenedPct(b) }}%)</span>
                            </template>
                        </div>
                    </div>
                    <div class="h-1.5 rounded-full bg-gray-900/80 overflow-hidden">
                        <div class="h-full transition-[width] duration-500"
                             :class="statusColor(b)"
                             :style="{ width: tweenedPct(b) + '%' }"></div>
                    </div>
                    <!-- P.1.1: surface the active country's intra-file
                         sub-progress under the running bar so the operator
                         sees movement even when one country's file is huge
                         and the bar's headline number can't tick yet.
                         P.1.2: when backend supplies progress_current AND
                         progress_total, render with the same tweened-number
                         + percentage treatment the headline bar uses;
                         otherwise fall back to the raw sub_phase string. -->
                    <div v-if="b.status === 'running' && activeSubPhase"
                         class="mt-1 text-[10px] text-gray-400 tabular-nums">
                        <span class="text-gray-500">currently</span>
                        <span class="text-gray-300 ml-1">{{ activeIso }}</span>
                        <span class="text-gray-500 ml-1">·</span>
                        <template v-if="activeProgressCurrent != null && activeProgressTotal != null">
                            <span class="ml-1 capitalize">{{ activeProgressUnit || 'features' }}</span>
                            <span class="ml-1">{{ fmtNum(_tweened['current:progress'] ?? activeProgressCurrent) }}</span>
                            <span class="text-gray-500 ml-1">of</span>
                            <span class="ml-1">{{ fmtNum(activeProgressTotal) }}</span>
                            <span class="text-gray-500 ml-1">({{ activeProgressPct }}%)</span>
                        </template>
                        <template v-else>
                            <span class="ml-1">{{ activeSubPhase }}</span>
                        </template>
                    </div>
                    <div class="flex items-baseline justify-between mt-1 text-[10px] tabular-nums"
                         :class="b.status === 'pending' ? 'text-gray-600' : 'text-gray-500'">
                        <span v-if="b.status === 'pending'">queued</span>
                        <span v-else>elapsed {{ fmtDuration(elapsedSeconds(b)) }}</span>
                        <span v-if="b.status === 'running' && etaSeconds(b)">
                            eta {{ fmtDuration(etaSeconds(b)) }}
                        </span>
                        <span v-else-if="b.status === 'done'" class="text-emerald-500">done</span>
                        <span v-else-if="b.status === 'pending'">waiting…</span>
                        <span v-else>—</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Population (WorldPop) ─────────────────────────────── -->
        <section v-if="wpSummary || wpCountryBars.length" class="space-y-2">
            <header class="flex items-baseline justify-between text-xs text-gray-400 uppercase tracking-wider gap-3">
                <span>Population</span>
                <div class="flex items-baseline gap-3 normal-case tracking-normal">
                    <span v-if="wpElapsed !== null" class="text-gray-500 text-[11px] tabular-nums">
                        total {{ fmtDuration(wpElapsed) }}
                    </span>
                    <span v-if="phase === 'worldpop' && isRunning" class="text-blue-400">
                        in progress
                    </span>
                </div>
            </header>

            <!-- Country progress summary (X of Y countries done) -->
            <div v-if="wpSummary"
                 class="rounded border border-gray-800 px-3 py-2 bg-gray-900/40">
                <div class="flex items-baseline justify-between mb-1 gap-2">
                    <div class="text-sm text-gray-200">
                        Countries
                        <span v-if="wpSummary.current_iso" class="text-gray-400 ml-2 text-xs">
                            currently {{ wpSummary.current_iso }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-400 tabular-nums shrink-0">
                        <!-- P.1.1: tweened summary count + percentage so the
                             "Countries 215 → 216" step animates over 1.4 s
                             with ease-out, matching the per-bar smoothing. -->
                        {{ fmtNum(_tweened['wp:summary'] ?? wpSummary.done) }}
                        / {{ fmtNum(wpSummary.total) }}
                        <span class="text-gray-500">({{ wpSummaryPctTweened }}%)</span>
                    </div>
                </div>
                <div class="h-2 rounded-full bg-gray-900/80 overflow-hidden">
                    <div class="h-full bg-blue-500 transition-[width] duration-500"
                         :style="{ width: wpSummaryPctTweened + '%' }"></div>
                </div>
            </div>

            <!-- Current country's per-step bars (load + per-ADM) -->
            <div v-if="wpCountryBars.length" class="space-y-1.5 ml-3 border-l-2 border-gray-800 pl-3">
                <div v-for="b in wpCountryBars" :key="b.key"
                     class="rounded border border-gray-800 px-3 py-2"
                     :class="trackColor(b)">
                    <div class="flex items-baseline justify-between mb-1 gap-2">
                        <div class="text-sm text-gray-200 truncate">{{ b.label }}</div>
                        <div class="text-xs text-gray-400 tabular-nums shrink-0">
                            <template v-if="b.total">
                                {{ fmtNum(b.current) }} / {{ fmtNum(b.total) }}
                                <span class="text-gray-500">({{ pct(b) }}%)</span>
                            </template>
                            <template v-else>
                                <span class="text-gray-500">—</span>
                            </template>
                        </div>
                    </div>
                    <div class="h-1.5 rounded-full bg-gray-900/80 overflow-hidden">
                        <div class="h-full transition-[width] duration-500"
                             :class="statusColor(b)"
                             :style="{ width: (b.total ? pct(b) : (b.status === 'running' ? 60 : 100)) + '%' }"></div>
                    </div>
                    <div class="flex items-baseline justify-between mt-1 text-[10px] text-gray-500 tabular-nums">
                        <span>elapsed {{ fmtDuration(elapsedSeconds(b)) }}</span>
                        <span v-if="b.status === 'running' && etaSeconds(b)">
                            eta {{ fmtDuration(etaSeconds(b)) }}
                        </span>
                        <span v-else-if="b.status === 'done'" class="text-emerald-500">done</span>
                        <span v-else>—</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Cleanup (post-Phase-2 fixups) ───────────────────────────── -->
        <!-- Phase T.3 — surfaces two bars routed via heartbeat keys
             `cleanup:topo_fallback` (single-pass row rescue) and
             `cleanup:t3_correction` (per-ISO pixel-attribution gap + overlap
             correction; total = 232 countries). Shape mirrors the Boundaries
             section since both are flat bar lists with consistent
             tween / elapsed / eta semantics. -->
        <section v-if="cleanupBars.length" class="space-y-2">
            <header class="flex items-baseline justify-between text-xs text-gray-400 uppercase tracking-wider gap-3">
                <span>Cleanup</span>
                <div class="flex items-baseline gap-3 normal-case tracking-normal">
                    <span v-if="cleanupElapsed !== null" class="text-gray-500 text-[11px] tabular-nums">
                        total {{ fmtDuration(cleanupElapsed) }}
                    </span>
                    <span v-if="phase === 'cleanup' && isRunning" class="text-blue-400">
                        in progress
                    </span>
                </div>
            </header>
            <div class="space-y-1.5">
                <div v-for="b in cleanupBars" :key="b.key"
                     class="rounded border px-3 py-2"
                     :class="[trackColor(b), b.status === 'pending' ? 'border-gray-800/60 opacity-70' : 'border-gray-800']">
                    <div class="flex items-baseline justify-between mb-1 gap-2">
                        <div class="text-sm truncate" :class="labelColor(b)">{{ b.label }}</div>
                        <div class="text-xs tabular-nums shrink-0"
                             :class="b.status === 'pending' ? 'text-gray-600' : 'text-gray-400'">
                            <template v-if="b.status === 'pending'">
                                {{ fmtNum(b.total) }} {{ b.unit || 'features' }} expected
                            </template>
                            <template v-else-if="b.total">
                                {{ fmtNum(tweened(b)) }} / {{ fmtNum(b.total) }} {{ b.unit || 'features' }}
                                <span class="text-gray-500">({{ tweenedPct(b) }}%)</span>
                            </template>
                            <template v-else>
                                <!-- topological fallback's "passes" bar reads as 1 / 1 once
                                     the SQL UPDATE returns; before then we show a generic
                                     working-spinner indicator. -->
                                <span class="text-gray-500">{{ b.status === 'running' ? 'running…' : '—' }}</span>
                            </template>
                        </div>
                    </div>
                    <div class="h-1.5 rounded-full bg-gray-900/80 overflow-hidden">
                        <div class="h-full transition-[width] duration-500"
                             :class="statusColor(b)"
                             :style="{ width: (b.total ? tweenedPct(b) : (b.status === 'running' ? 60 : 100)) + '%' }"></div>
                    </div>
                    <!-- Surface the active ISO under the running correction bar so the
                         operator sees motion through the 232 countries without waiting
                         for the headline counter to tick. -->
                    <div v-if="b.status === 'running' && b.key === 'cleanup:t3_correction' && activeIso"
                         class="mt-1 text-[10px] text-gray-400 tabular-nums">
                        <span class="text-gray-500">currently</span>
                        <span class="text-gray-300 ml-1">{{ activeIso }}</span>
                        <span v-if="activeSubPhase" class="text-gray-500 ml-1">·</span>
                        <span v-if="activeSubPhase" class="ml-1">{{ activeSubPhase }}</span>
                    </div>
                    <div class="flex items-baseline justify-between mt-1 text-[10px] tabular-nums"
                         :class="b.status === 'pending' ? 'text-gray-600' : 'text-gray-500'">
                        <span v-if="b.status === 'pending'">queued</span>
                        <span v-else>elapsed {{ fmtDuration(elapsedSeconds(b)) }}</span>
                        <span v-if="b.status === 'running' && etaSeconds(b)">
                            eta {{ fmtDuration(etaSeconds(b)) }}
                        </span>
                        <span v-else-if="b.status === 'done'" class="text-emerald-500">done</span>
                        <span v-else-if="b.status === 'pending'">waiting…</span>
                        <span v-else>—</span>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div v-else class="text-xs text-gray-500 italic">
        Waiting for the ETL to start…
    </div>
</template>
