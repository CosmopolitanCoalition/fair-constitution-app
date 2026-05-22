<script setup>
import { computed, ref, watch } from 'vue'

/**
 * EventToasts — Phase P.3 surface for structured events emitted by the
 * Python ETL via `heartbeat.emit_event()`.
 *
 * Receives `events` (an array of payloads with shape
 *   { id, ts, level: 'info'|'warn'|'error', type, msg, iso, name,
 *     adm_level, phase, ... })
 * each poll. Renders three sections:
 *
 *   1. Banner row — persistent for level=error events. Operator dismisses
 *      manually; banners stack at the top.
 *   2. Toast row — level=warn events that auto-dismiss after 8s.
 *   3. Feed — collapsible list of all events (info+warn+error) with
 *      timestamp, type, iso, msg. Default-collapsed when no errors.
 *
 * Dedup is by event.id (server-generated md5 of payload). Toast / banner
 * dismissals are remembered locally so re-polled events stay dismissed.
 */

const props = defineProps({
    events: { type: Array, default: () => [] },
})

const dismissedIds = ref(new Set())
// Auto-fading toasts: track each warn event's first-seen time; events older
// than TOAST_TTL_MS get hidden from the toast row but stay in the feed.
const seenAt       = ref(new Map())   // id -> timestamp
const TOAST_TTL_MS = 8000

const feedExpanded = ref(false)

// Stamp first-seen time on new event ids so we can fade them out.
watch(() => props.events, (evts) => {
    const now = Date.now()
    for (const e of evts) {
        if (!e?.id) continue
        if (!seenAt.value.has(e.id)) seenAt.value.set(e.id, now)
    }
}, { deep: true, immediate: true })

const errorBanners = computed(() =>
    (props.events || [])
        .filter(e => e.level === 'error' && !dismissedIds.value.has(e.id))
)

const warnToasts = computed(() => {
    const now = Date.now()
    return (props.events || [])
        .filter(e => e.level === 'warn' && !dismissedIds.value.has(e.id))
        .filter(e => {
            const t = seenAt.value.get(e.id)
            return t == null || (now - t) < TOAST_TTL_MS
        })
        .slice(-3)   // most recent 3
})

// Re-evaluate the warn-toast filter on a tick so toasts auto-fade.
let tickTimer = null
const tick = ref(0)
function startTicker() {
    if (tickTimer) return
    tickTimer = setInterval(() => { tick.value++ }, 1000)
}
function stopTicker() {
    if (tickTimer) { clearInterval(tickTimer); tickTimer = null }
}
import { onMounted, onBeforeUnmount } from 'vue'
onMounted(startTicker)
onBeforeUnmount(stopTicker)

const allFeed = computed(() => {
    // Force re-evaluation on tick so the feed stays in sync with seenAt.
    void tick.value
    return (props.events || []).slice().reverse()   // newest first
})

const errorCount = computed(() => errorBanners.value.length)
const warnCount  = computed(() =>
    (props.events || []).filter(e => e.level === 'warn').length
)
const infoCount  = computed(() =>
    (props.events || []).filter(e => e.level === 'info').length
)

function dismiss(id) {
    if (!id) return
    dismissedIds.value = new Set([...dismissedIds.value, id])
}

function fmtTime(ts) {
    if (!ts) return ''
    const d = new Date(ts * 1000)
    if (Number.isNaN(d.getTime())) return ''
    return d.toLocaleTimeString([], { hour12: false })
}

function levelClass(level) {
    if (level === 'error') return 'bg-red-900/40 border-red-700 text-red-100'
    if (level === 'warn')  return 'bg-amber-950/40 border-amber-700 text-amber-100'
    return 'bg-gray-900/40 border-gray-700 text-gray-200'
}

function eventLabel(e) {
    const parts = []
    if (e.type) parts.push(e.type.replace(/_/g, ' '))
    if (e.iso)  parts.push(e.iso)
    if (e.name) parts.push(`'${e.name}'`)
    if (e.adm_level !== undefined && e.adm_level !== null) {
        parts.push(`L${e.adm_level}`)
    }
    return parts.join(' · ')
}

const noEvents = computed(() => (props.events || []).length === 0)
</script>

<template>
    <div v-if="!noEvents" class="space-y-2">
        <!-- Persistent error banners ────────────────────── -->
        <div v-for="e in errorBanners" :key="e.id"
             class="rounded border px-3 py-2"
             :class="levelClass('error')">
            <div class="flex items-baseline justify-between gap-2 mb-1">
                <div class="text-xs uppercase tracking-wider text-red-300">
                    {{ eventLabel(e) || 'error' }}
                </div>
                <div class="flex items-baseline gap-2">
                    <span class="text-[10px] text-red-300/70 tabular-nums">
                        {{ fmtTime(e.ts) }}
                    </span>
                    <button type="button" @click="dismiss(e.id)"
                            class="text-[10px] text-red-300 hover:text-red-100">
                        dismiss
                    </button>
                </div>
            </div>
            <div class="text-sm">{{ e.msg || '(no message)' }}</div>
        </div>

        <!-- Auto-fading warning toasts ──────────────────── -->
        <div v-for="e in warnToasts" :key="'toast:' + e.id"
             class="rounded border px-3 py-2 text-xs"
             :class="levelClass('warn')">
            <div class="flex items-baseline justify-between gap-2">
                <div class="font-medium">
                    {{ eventLabel(e) || 'warning' }}
                </div>
                <span class="text-[10px] text-amber-300/70 tabular-nums">
                    {{ fmtTime(e.ts) }}
                </span>
            </div>
            <div class="text-amber-100/90 mt-0.5">{{ e.msg || '' }}</div>
        </div>

        <!-- Collapsible feed ───────────────────────────── -->
        <div class="rounded border border-gray-800 bg-gray-950/60">
            <button type="button"
                    @click="feedExpanded = !feedExpanded"
                    class="w-full px-3 py-2 flex items-center justify-between text-xs text-gray-400 hover:bg-gray-900/40">
                <span>
                    Events
                    <span class="text-red-400 ml-2" v-if="errorCount">{{ errorCount }}E</span>
                    <span class="text-amber-400 ml-1" v-if="warnCount">{{ warnCount }}W</span>
                    <span class="text-gray-500 ml-1" v-if="infoCount">{{ infoCount }}i</span>
                </span>
                <span>{{ feedExpanded ? '▾' : '▸' }}</span>
            </button>
            <div v-if="feedExpanded" class="max-h-72 overflow-y-auto px-3 py-2 space-y-1 text-xs font-mono">
                <div v-for="e in allFeed" :key="'feed:' + e.id"
                     class="flex items-baseline gap-2"
                     :class="{
                        'text-red-300':   e.level === 'error',
                        'text-amber-300': e.level === 'warn',
                        'text-gray-400':  e.level !== 'error' && e.level !== 'warn',
                     }">
                    <span class="text-gray-600 tabular-nums shrink-0">{{ fmtTime(e.ts) || '—' }}</span>
                    <span class="uppercase text-[10px] tracking-wider shrink-0 w-12">{{ e.level }}</span>
                    <span class="shrink-0">{{ eventLabel(e) }}</span>
                    <span class="text-gray-500 truncate">{{ e.msg }}</span>
                </div>
                <div v-if="!allFeed.length" class="text-gray-600 italic">
                    No events yet.
                </div>
            </div>
        </div>
    </div>
</template>
