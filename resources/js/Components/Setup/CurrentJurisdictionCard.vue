<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import MiniMap from './MiniMap.vue'
import QueueBadges from './QueueBadges.vue'

const props = defineProps({
    current:   { type: Object, default: null },
    lifecycle: { type: String, default: 'idle' },
    // Phase P.1: when rendered alongside the new <StackedProgressBars />,
    // hide the redundant per-jurisdiction progress bar inside this card.
    // The card still carries the iso-level minimap + name + ancestry chain
    // (P.2 simplifies this to once-per-country switching).
    compact:   { type: Boolean, default: false },
})

const now = ref(Date.now())
let tickTimer = null

// Root-first ancestor chain for the current jurisdiction
// [{ id, name, adm_level }, ...] — renders above the title as a breadcrumb
// (Earth › USA › Alabama › Madison County) so the user can place a county
// in its hierarchy at a glance.
const ancestors = ref([])
let ancestorsReqId = 0  // guards against stale fetches when id flips fast

const currentId = computed(() => props.current?.id || null)

async function loadAncestors(id) {
    const myReq = ++ancestorsReqId
    if (!id) { ancestors.value = []; return }
    try {
        const res = await fetch(`/api/jurisdictions/${id}/ancestors`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (myReq !== ancestorsReqId) return
        if (!res.ok) { ancestors.value = []; return }
        const data = await res.json()
        if (myReq !== ancestorsReqId) return
        // Drop the leaf — it's already rendered as the big title.
        const chain = Array.isArray(data?.chain) ? data.chain : []
        ancestors.value = chain.slice(0, -1)
    } catch {
        if (myReq !== ancestorsReqId) return
        ancestors.value = []
    }
}

watch(currentId, (id) => loadAncestors(id), { immediate: true })

onMounted(() => {
    tickTimer = setInterval(() => { now.value = Date.now() }, 1000)
})
onBeforeUnmount(() => { if (tickTimer) clearInterval(tickTimer) })

const elapsed = computed(() => {
    const t = props.current?.started_at
    if (!t) return ''
    const started = new Date(t).getTime()
    if (!Number.isFinite(started)) return ''
    const secs = Math.max(0, Math.round((now.value - started) / 1000))
    const m = Math.floor(secs / 60)
    const s = secs % 60
    return `${m}m ${s.toString().padStart(2, '0')}s`
})

const admLabel = computed(() => {
    const lvl = props.current?.adm_level
    if (lvl == null) return null
    // Singular natural-language labels — used for the "currently processing"
    // card title. Mirror of the canonical PHP map at
    // SetupController::jurisdictionsCounts() (which uses pluralized forms
    // for the grid). Keep both in sync.
    const map = {
        0: 'Planet',
        1: 'Country',
        2: 'State / Province',
        3: 'County',
        4: 'Municipality',
        5: 'Township',
        6: 'Neighborhood',
    }
    return map[lvl] ?? `Level ${lvl}`
})

const phaseTone = computed(() => {
    switch (props.current?.phase) {
        case 'geoboundaries': return 'bg-indigo-900/50 text-indigo-200 border-indigo-800'
        case 'worldpop':      return 'bg-emerald-900/50 text-emerald-200 border-emerald-800'
        case 'transition':    return 'bg-amber-900/50 text-amber-200 border-amber-800'
        default:              return 'bg-gray-800 text-gray-400 border-gray-700'
    }
})

const phaseLabel = computed(() => {
    const p = props.current?.phase
    if (!p) return ''
    // User-facing labels — abstract away the data-source names. Backend phase
    // identifiers stay as 'geoboundaries' / 'worldpop' for DB compatibility.
    const names = {
        geoboundaries: 'Boundaries',
        worldpop:      'Population',
        transition:    'Transition',
    }
    return names[p] ?? p
})

const title = computed(() => {
    const c = props.current
    if (!c) return ''
    if (c.phase === 'transition') return 'Starting next phase…'
    return c.name || c.iso_code || 'Processing'
})

function fmtPop(n) {
    if (n == null) return null
    return Number(n).toLocaleString()
}

// ── Progress bar + ETA ───────────────────────────────────────────────────────
// Heartbeat carries numeric progress_current/progress_total fields. When both
// are present, we render a slim emerald progress bar + ETA. The bar advances
// SMOOTHLY every animation frame via rate-based extrapolation: between server
// events, the displayed value is projected forward from the last known sample
// at the rolling rate. The user sees continuous motion, not chunky jumps.

const RATE_SAMPLE_CAP = 8
const rateSamples = ref([])     // [{ ts, current }, ...]

// Animated/projected current — what we actually display in the bar.
// Driven by requestAnimationFrame; re-anchored on every poll.
const displayCurrent = ref(0)
let rafHandle = null

watch(
    () => props.current?.sub_phase,
    () => {
        // New sub_phase → drop stale samples + reset the displayed value
        // so we don't briefly extrapolate from a finished sub_phase's rate
        // into the new one.
        rateSamples.value = []
        displayCurrent.value = props.current?.progress_current ?? 0
    },
)

watch(
    () => [props.current?.progress_current, props.current?.progress_total],
    ([c, t]) => {
        if (!Number.isFinite(c) || !Number.isFinite(t) || t <= 0) return
        const buf = rateSamples.value
        const last = buf[buf.length - 1]
        // Only push if `current` actually changed — avoids piling up identical
        // samples when the heartbeat hasn't advanced.
        if (last && last.current === c) return
        buf.push({ ts: Date.now(), current: c })
        if (buf.length > RATE_SAMPLE_CAP) buf.shift()
    },
)

function currentRate() {
    const buf = rateSamples.value
    if (buf.length < 2) return 0
    const oldest = buf[0]
    const latest = buf[buf.length - 1]
    const dCur = latest.current - oldest.current
    const dSec = (latest.ts - oldest.ts) / 1000
    if (dCur <= 0 || dSec <= 0) return 0
    return dCur / dSec
}

function startInterpolation() {
    cancelAnimationFrame(rafHandle)
    const tick = () => {
        const c = props.current?.progress_current
        const t = props.current?.progress_total
        if (Number.isFinite(c) && Number.isFinite(t) && t > 0) {
            const buf = rateSamples.value
            if (buf.length >= 2) {
                const latest = buf[buf.length - 1]
                const rate    = currentRate()
                const elapsed = (Date.now() - latest.ts) / 1000
                // Cap extrapolation at 1.5× the rate so a stalled backend
                // can't extrapolate the bar to 100% before the next poll.
                const projected = latest.current + Math.max(0, rate) * Math.min(elapsed, 1.5 / Math.max(rate, 0.0001))
                displayCurrent.value = Math.min(t, Math.max(c, projected))
            } else {
                displayCurrent.value = c
            }
        }
        rafHandle = requestAnimationFrame(tick)
    }
    tick()
}
onMounted(startInterpolation)
onBeforeUnmount(() => cancelAnimationFrame(rafHandle))

const progress = computed(() => {
    const t = props.current?.progress_total
    if (!Number.isFinite(t) || t <= 0) return null
    const c = displayCurrent.value
    return { current: c, total: t, pct: Math.min(100, (c / t) * 100) }
})

const eta = computed(() => {
    const p = progress.value
    if (!p) return ''
    const rate = currentRate()
    if (rate <= 0) return ''
    const remaining = Math.max(0, p.total - p.current)
    const secs = Math.round(remaining / rate)
    if (secs < 60) return `~${secs}s`
    const m = Math.floor(secs / 60)
    const s = secs % 60
    if (m < 60) return `~${m}m ${s.toString().padStart(2, '0')}s`
    const h = Math.floor(m / 60)
    const mm = m % 60
    return `~${h}h ${mm.toString().padStart(2, '0')}m`
})
</script>

<template>
    <div
        class="bg-gradient-to-br from-gray-900 via-gray-900 to-gray-950 border border-gray-800 rounded-lg p-4"
    >
        <div v-if="!current && lifecycle === 'running'" class="flex items-center gap-3 text-gray-500 text-sm">
            <span class="w-2 h-2 bg-blue-500 rounded-full animate-pulse" />
            Waiting for first heartbeat…
        </div>

        <div v-else-if="!current" class="text-gray-500 text-sm italic">
            No job running.
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_320px] gap-4">
            <!-- Left: info column -->
            <div class="flex flex-col gap-3 min-w-0">
                <div>
                    <div class="text-gray-500 text-xs uppercase tracking-wider">
                        Currently processing
                    </div>
                    <div
                        v-if="ancestors.length"
                        class="text-gray-400 text-xs mt-0.5 flex items-center gap-1 flex-wrap"
                    >
                        <template v-for="(a, i) in ancestors" :key="a.id">
                            <span class="truncate">{{ a.name }}</span>
                            <span v-if="i < ancestors.length - 1" class="text-gray-600">›</span>
                            <span v-else class="text-gray-600">›</span>
                        </template>
                    </div>
                    <div class="text-white text-2xl font-semibold truncate">
                        {{ title }}
                    </div>
                    <div class="text-gray-400 text-xs font-mono mt-0.5">
                        <span v-if="current.iso_code">{{ current.iso_code }}</span>
                        <span v-if="current.iso_code && admLabel"> · </span>
                        <span v-if="admLabel">{{ admLabel }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <span
                        v-if="phaseLabel"
                        class="text-xs px-2 py-1 rounded font-mono border"
                        :class="phaseTone"
                    >
                        {{ phaseLabel }}
                    </span>
                    <span
                        v-if="current.sub_phase"
                        class="text-xs px-2 py-1 rounded font-mono bg-gray-950 border border-gray-800 text-gray-300 truncate max-w-full"
                    >
                        {{ current.sub_phase }}
                    </span>
                    <span
                        v-if="elapsed"
                        class="text-xs px-2 py-1 rounded font-mono bg-gray-950 border border-gray-800 text-gray-400"
                    >
                        {{ elapsed }}
                    </span>
                </div>

                <!-- Numeric sub-phase progress bar + ETA. Renders only when the
                     heartbeat carries progress_current/progress_total AND we're
                     not in compact mode (Phase P.1 — the StackedProgressBars
                     panel beside this card already shows per-bar progress, so
                     this redundant bar gets hidden when both render together). -->
                <div v-if="progress && !compact" class="space-y-1">
                    <div class="relative h-2 bg-gray-800 rounded overflow-hidden">
                        <div
                            class="absolute inset-y-0 left-0 bg-emerald-500/70 transition-[width] duration-150 ease-out"
                            :style="{ width: progress.pct + '%' }"
                        />
                    </div>
                    <div class="flex justify-between text-[10px] font-mono text-gray-400">
                        <span>{{ progress.pct.toFixed(1) }}%</span>
                        <span v-if="eta">{{ eta }} remaining</span>
                    </div>
                </div>

                <div v-if="current.population != null || current.area_km2 != null" class="grid grid-cols-2 gap-2 text-sm">
                    <div v-if="current.population != null" class="bg-gray-950 border border-gray-800 rounded p-2">
                        <div class="text-gray-500 text-xs">Population</div>
                        <div class="text-gray-100 font-mono">{{ fmtPop(current.population) }}</div>
                    </div>
                    <div v-if="current.area_km2 != null" class="bg-gray-950 border border-gray-800 rounded p-2">
                        <div class="text-gray-500 text-xs">Area (km²)</div>
                        <div class="text-gray-100 font-mono">{{ fmtPop(Math.round(current.area_km2)) }}</div>
                    </div>
                </div>

                <QueueBadges :isos="current.queue_preview || []" />
            </div>

            <!-- Right: minimap.
                 P.1.1: only request the raster overlay during WorldPop
                 phase. In geoboundaries phase, any raster the endpoint
                 returns is leftover from a previous run (--fresh purges
                 jurisdictions but raster cleanup is per-country at load
                 time) and renders as misleading population data. -->
            <div class="h-[220px] md:h-[200px] md:min-w-[320px]">
                <MiniMap
                    :jurisdiction-id="current.id || null"
                    :show-raster="current.phase === 'worldpop'"
                />
            </div>
        </div>
    </div>
</template>
