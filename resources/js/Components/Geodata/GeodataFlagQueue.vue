<template>
    <div class="bg-gray-800 rounded-lg p-3">
        <!-- Header: title + scan control -->
        <div class="flex items-center justify-between mb-2">
            <div class="text-xs text-gray-400">Data Review &amp; Repair</div>
            <button
                v-if="!readOnly"
                type="button"
                @click="runScan"
                :disabled="scanBusy || scanRunning"
                class="text-[11px] font-medium px-2 py-0.5 rounded border transition-colors
                       bg-gray-900 border-gray-600 text-gray-300
                       hover:text-white hover:border-gray-400
                       disabled:opacity-50 disabled:cursor-not-allowed">
                {{ scanRunning ? 'Scanning…' : (scanBusy ? 'Starting…' : 'Run scan') }}
            </button>
        </div>

        <!-- Read-only notice — post-acceptance the repair window is closed;
             the queue stays browsable as an audit surface but every mutating
             affordance is hidden. -->
        <div v-if="readOnly" class="mb-2 text-[11px] text-gray-500 italic">
            Map data accepted — repairs are locked.
        </div>

        <!-- Scan status line -->
        <div v-if="scanRunning" class="mb-2 flex items-center gap-1.5 text-[11px] text-indigo-300">
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
            Scan running{{ scanStatus?.started_at ? ` since ${formatTime(scanStatus.started_at)}` : '' }}…
        </div>
        <div v-else-if="scanStatus?.finished_at" class="mb-2 text-[11px] text-gray-500">
            Last scan {{ formatTime(scanStatus.finished_at) }}
        </div>
        <div v-if="scanError" class="mb-2 text-[11px] text-red-400">{{ scanError }}</div>

        <!-- Tab switcher: flag queue | repairs log -->
        <div class="flex gap-1 mb-2">
            <button type="button" @click="tab = 'flags'"
                    class="px-2 py-0.5 rounded text-[11px] border transition-colors"
                    :class="tab === 'flags'
                        ? 'bg-gray-700 border-gray-500 text-white'
                        : 'bg-gray-900 border-gray-700 text-gray-400 hover:text-white'">
                Flags
            </button>
            <button type="button" @click="switchToRepairs"
                    class="px-2 py-0.5 rounded text-[11px] border transition-colors"
                    :class="tab === 'repairs'
                        ? 'bg-gray-700 border-gray-500 text-white'
                        : 'bg-gray-900 border-gray-700 text-gray-400 hover:text-white'">
                Repairs log
            </button>
        </div>

        <!-- ══ FLAGS TAB ══ -->
        <template v-if="tab === 'flags'">
            <!-- Status filter + counts. Buttons double as the open/accepted/
                 resolved counters so the numbers are always visible. -->
            <div class="flex gap-1 mb-2">
                <button v-for="s in ['open', 'accepted', 'resolved']" :key="s"
                        type="button" @click="setStatusFilter(s)"
                        class="px-2 py-0.5 rounded text-[11px] border transition-colors capitalize"
                        :class="statusFilter === s
                            ? 'bg-blue-900/60 border-blue-600 text-blue-200'
                            : 'bg-gray-900 border-gray-700 text-gray-400 hover:text-white'">
                    {{ s }} {{ counts?.[s] ?? 0 }}
                </button>
            </div>

            <!-- Open-severity summary chips (only meaningful on the open filter) -->
            <div v-if="statusFilter === 'open' && (counts?.open ?? 0) > 0"
                 class="flex flex-wrap gap-1.5 mb-2">
                <span v-if="openBySeverity.critical > 0"
                      class="px-2 py-0.5 rounded text-xs bg-red-900 text-red-200 border border-red-700">
                    {{ openBySeverity.critical }} critical
                </span>
                <span v-if="openBySeverity.warning > 0"
                      class="px-2 py-0.5 rounded text-xs bg-amber-900 text-amber-200 border border-amber-700">
                    {{ openBySeverity.warning }} warning
                </span>
                <span v-if="openBySeverity.info > 0"
                      class="px-2 py-0.5 rounded text-xs bg-gray-700 text-gray-300 border border-gray-600">
                    {{ openBySeverity.info }} info
                </span>
            </div>

            <div v-if="flagsError" class="text-[11px] text-red-400 mb-2">{{ flagsError }}</div>
            <div v-if="loadingFlags && flags.length === 0" class="text-[11px] text-gray-500 italic">
                Loading flags…
            </div>
            <div v-else-if="flags.length === 0" class="text-[11px] text-gray-500 italic">
                <template v-if="statusFilter === 'open'">
                    No open flags. Run a scan to (re)check the imported data.
                </template>
                <template v-else>No {{ statusFilter }} flags.</template>
            </div>

            <!-- Truncation notice: the API caps the list at 500 rows but the
                 counts are real totals — never let a capped list read as
                 "that's everything" (world-scale chain scans run to thousands). -->
            <div v-if="flagsTruncated"
                 class="mb-2 px-2 py-1.5 rounded border border-amber-800 bg-amber-950/40 text-[11px] text-amber-200">
                Showing the {{ flags.length }} most severe of {{ flagsTotal }} {{ statusFilter }} flags —
                filter by category, or work the queue down and refresh.
            </div>

            <!-- Flags grouped by category -->
            <div v-for="group in groupedFlags" :key="group.category" class="mb-2 last:mb-0">
                <div class="text-[10px] uppercase font-semibold text-gray-500 mb-1">
                    {{ categoryLabel(group.category) }}
                    <span class="text-gray-600 normal-case font-normal ml-1">{{ group.flags.length }}</span>
                </div>
                <div class="space-y-1">
                    <div v-for="flag in group.flags" :key="flag.id"
                         class="rounded border bg-gray-900/70"
                         :class="severityBorder(flag.severity)">
                        <!-- Row header — click to expand -->
                        <div class="flex items-start gap-1.5 px-2 py-1.5 cursor-pointer select-none"
                             @click="toggleExpanded(flag.id)">
                            <span class="shrink-0 px-1.5 py-0 rounded text-[10px] mt-0.5"
                                  :class="severityChip(flag.severity)">
                                {{ flag.severity }}
                            </span>
                            <span class="flex-1 text-[11px] text-gray-200 leading-snug">{{ flag.title }}</span>
                            <span class="text-gray-600 text-xs transition-transform shrink-0"
                                  :class="expandedId === flag.id ? 'rotate-90' : ''">›</span>
                        </div>

                        <!-- Expanded evidence + actions -->
                        <div v-if="expandedId === flag.id" class="px-2 pb-2 border-t border-gray-800 pt-1.5">
                            <div class="text-[10px] text-gray-500 mb-1.5">
                                detected {{ formatTime(flag.detected_at) }}
                                <template v-if="flag.status !== 'open'">
                                    · {{ flag.status }}{{ flag.resolved_at ? ` ${formatTime(flag.resolved_at)}` : '' }}
                                </template>
                            </div>

                            <!-- Payload evidence: key → value; slug-like strings
                                 (and slug arrays) link into the viewer. -->
                            <div class="space-y-1 mb-2">
                                <div v-for="(value, key) in (flag.payload || {})" :key="key"
                                     class="text-[11px]">
                                    <span class="text-gray-500">{{ String(key).replace(/_/g, ' ') }}:</span>
                                    <template v-if="Array.isArray(value)">
                                        <span class="inline-flex flex-wrap gap-1 ml-1 align-top">
                                            <template v-for="(item, i) in value" :key="i">
                                                <a v-if="isSlugLike(item)" :href="`/jurisdictions/${item}`"
                                                   class="font-mono text-sky-300 hover:text-sky-200 underline decoration-sky-800">{{ item }}</a>
                                                <a v-else-if="item && typeof item === 'object' && isSlugLike(item.slug)"
                                                   :href="`/jurisdictions/${item.slug}`"
                                                   class="font-mono text-sky-300 hover:text-sky-200 underline decoration-sky-800">{{ item.name || item.slug }}</a>
                                                <span v-else class="font-mono text-gray-300">{{ formatScalar(item) }}</span>
                                            </template>
                                        </span>
                                    </template>
                                    <a v-else-if="isSlugLike(value)" :href="`/jurisdictions/${value}`"
                                       class="font-mono text-sky-300 hover:text-sky-200 underline decoration-sky-800 ml-1">{{ value }}</a>
                                    <span v-else class="font-mono text-gray-300 ml-1 break-all">{{ formatScalar(value) }}</span>
                                </div>
                            </div>

                            <!-- Resolution note (accepted / resolved flags) -->
                            <div v-if="flag.resolution" class="mb-2 text-[11px] text-gray-400">
                                <span class="text-gray-500">resolution:</span>
                                <span class="font-mono break-all ml-1">{{ formatScalar(flag.resolution) }}</span>
                            </div>

                            <!-- Actions — only while the repair window is open
                                 and the flag itself is still open. -->
                            <div v-if="!readOnly && flag.status === 'open'" class="flex flex-wrap gap-1.5">
                                <button v-if="flag.suggested_action && flag.suggested_action !== 'accept_flag'"
                                        type="button"
                                        @click.stop="openModal(flag.suggested_action, flag)"
                                        class="px-2 py-1 rounded text-[11px] font-medium
                                               bg-blue-800 hover:bg-blue-700 text-blue-100 transition-colors">
                                    {{ actionLabel(flag.suggested_action) }}
                                </button>
                                <button type="button"
                                        @click.stop="openModal('accept_flag', flag)"
                                        class="px-2 py-1 rounded text-[11px] font-medium
                                               bg-gray-700 hover:bg-gray-600 text-gray-200 transition-colors">
                                    Accept flag
                                </button>
                                <!-- Prune is the documented remedy for a dual coverage
                                     the operator rules OUT (e.g. a vendor-artifact
                                     absorption) — reachable here, not just where it is
                                     the suggested action. -->
                                <button v-if="flag.category === 'dual_coverage' && flag.suggested_action !== 'prune'"
                                        type="button"
                                        @click.stop="openModal('prune', flag)"
                                        class="px-2 py-1 rounded text-[11px] font-medium
                                               bg-gray-700 hover:bg-gray-600 text-gray-300 transition-colors">
                                    Prune…
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ══ REPAIRS LOG TAB ══ -->
        <template v-else>
            <div v-if="repairsError" class="text-[11px] text-red-400 mb-2">{{ repairsError }}</div>
            <div v-if="loadingRepairs && repairs.length === 0" class="text-[11px] text-gray-500 italic">
                Loading repairs…
            </div>
            <div v-else-if="repairs.length === 0" class="text-[11px] text-gray-500 italic">
                No repairs applied yet.
            </div>
            <div class="space-y-1">
                <div v-for="repair in repairs" :key="repair.id"
                     class="rounded border border-gray-700 bg-gray-900/70 px-2 py-1.5">
                    <div class="flex items-start gap-1.5">
                        <span class="shrink-0 px-1.5 py-0 rounded text-[10px] bg-gray-700 text-gray-200 border border-gray-600">
                            {{ actionLabel(repair.action) }}
                        </span>
                        <a v-if="isSlugLike(repair.target_slug)" :href="`/jurisdictions/${repair.target_slug}`"
                           class="flex-1 text-[11px] font-mono text-sky-300 hover:text-sky-200 truncate">
                            {{ repair.target_slug }}
                        </a>
                        <span v-else class="flex-1 text-[11px] font-mono text-gray-300 truncate">
                            {{ repair.target_slug }}
                        </span>
                    </div>
                    <div class="mt-1 text-[10px] text-gray-500">
                        applied {{ formatTime(repair.applied_at) }}
                        <span v-if="repair.params?.note" class="text-gray-400 italic">· {{ repair.params.note }}</span>
                    </div>
                    <div class="mt-1 flex items-center gap-2">
                        <span v-if="repair.reverted_at"
                              class="px-1.5 py-0 rounded text-[10px] bg-amber-900 text-amber-200 border border-amber-700">
                            reverted {{ formatTime(repair.reverted_at) }}
                        </span>
                        <button v-else-if="!readOnly"
                                type="button"
                                @click="revertRepair(repair)"
                                :disabled="revertBusyId !== null"
                                class="px-2 py-0.5 rounded text-[10px] font-medium
                                       bg-red-900/60 hover:bg-red-800 text-red-200 border border-red-800
                                       disabled:opacity-50 transition-colors">
                            {{ revertBusyId === repair.id ? 'Reverting…' : 'Revert' }}
                        </button>
                    </div>
                    <div v-if="revertBusyId === null && revertErrorId === repair.id"
                         class="mt-1 text-[10px] text-red-400">{{ revertError }}</div>
                </div>
            </div>
        </template>

        <!-- Repair modal (full-screen overlay so the narrow sidebar doesn't
             cramp the forms). -->
        <GeodataRepairModal
            v-if="modal"
            :mode="modal.mode"
            :flag="modal.flag"
            @close="modal = null"
            @applied="onRepairApplied"
        />
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { csrfFetch } from '@/lib/csrf'
import GeodataRepairModal from '@/Components/Geodata/GeodataRepairModal.vue'

// Data Review & Repair queue — the operator-facing surface over
// geodata_flags / geodata_repairs. Lives in the Jurisdiction Viewer sidebar
// at planet scope, above the Accept Map Data gate. All mutations run through
// GeodataRepairModal; this component owns fetching, grouping, and the
// scan-orchestration loop (POST scan → poll status → refresh flags).
const props = defineProps({
    // True once map_accepted_at is stamped — the repair window is closed and
    // every mutating affordance disappears (the backend enforces the same
    // window; this is presentation, not the gate).
    readOnly: { type: Boolean, default: false },
})

const emit = defineEmits(['counts'])

// Category display names, keyed by the exact backend category strings.
const CATEGORY_LABELS = {
    dual_coverage:        'Dual coverage',
    mis_anchored_cluster: 'Mis-anchored clusters',
    same_space_chain:     'Same-space chains',
    raster_coverage:      'Raster coverage',
    displaced_geometry:   'Displaced geometry',
    orphaned_rows:        'Orphaned rows',
}

const ACTION_LABELS = {
    accept_flag:          'Accept flag',
    synthesize_anchor:    'Synthesize anchor',
    merge_chain:          'Merge chain',
    reparent:             'Reparent',
    recompute_population: 'Recompute population',
    prune:                'Prune',
}

const tab           = ref('flags')
const statusFilter  = ref('open')
const flags         = ref([])
const flagsTotal     = ref(0)     // real total behind the API's 500-row cap
const flagsTruncated = ref(false)
const counts        = ref(null)
const loadingFlags  = ref(false)
const flagsError    = ref('')
const expandedId    = ref(null)

const scanBusy   = ref(false)   // POST /scan in flight
const scanStatus = ref(null)    // { running, started_at, finished_at, last_summary }
const scanError  = ref('')
let scanPollTimer = null

const repairs        = ref([])
const loadingRepairs = ref(false)
const repairsError   = ref('')
const revertBusyId   = ref(null)
const revertError    = ref('')
const revertErrorId  = ref(null)

const modal = ref(null)   // { mode, flag } | null

// ─── Formatting helpers ─────────────────────────────────────────────────────

function categoryLabel(cat) {
    return CATEGORY_LABELS[cat] || String(cat).replace(/_/g, ' ')
}
function actionLabel(action) {
    return ACTION_LABELS[action] || String(action).replace(/_/g, ' ')
}
function severityChip(sev) {
    if (sev === 'critical') return 'bg-red-900 text-red-200 border border-red-700'
    if (sev === 'warning')  return 'bg-amber-900 text-amber-200 border border-amber-700'
    return 'bg-gray-700 text-gray-300 border border-gray-600'
}
function severityBorder(sev) {
    if (sev === 'critical') return 'border-red-900/70'
    if (sev === 'warning')  return 'border-amber-900/70'
    return 'border-gray-700'
}
// Slug heuristic for linking payload evidence into the viewer: the ETL slug
// pattern is '{iso lower}-{adm_level}-{sanitized-name}' — lowercase segments
// joined by hyphens. Anything matching gets a /jurisdictions/{slug} link.
function isSlugLike(v) {
    return typeof v === 'string' && /^[a-z0-9]+(-[a-z0-9]+)+$/.test(v)
}
function formatScalar(v) {
    if (v === null || v === undefined) return '—'
    if (typeof v === 'object') return JSON.stringify(v)
    if (typeof v === 'number') return v.toLocaleString()
    return String(v)
}
function formatTime(iso) {
    if (!iso) return ''
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return iso
        return d.toLocaleString()
    } catch (e) {
        return iso
    }
}

// ─── Flags ──────────────────────────────────────────────────────────────────

const openBySeverity = computed(() =>
    counts.value?.open_by_severity ?? { critical: 0, warning: 0, info: 0 })

const groupedFlags = computed(() => {
    const byCat = new Map()
    for (const f of flags.value) {
        if (!byCat.has(f.category)) byCat.set(f.category, [])
        byCat.get(f.category).push(f)
    }
    // Stable presentation order: the known categories first, then any
    // unknown category the backend might grow.
    const order = Object.keys(CATEGORY_LABELS)
    return [...byCat.entries()]
        .sort((a, b) => {
            const ia = order.indexOf(a[0]); const ib = order.indexOf(b[0])
            return (ia === -1 ? order.length : ia) - (ib === -1 ? order.length : ib)
        })
        .map(([category, list]) => ({ category, flags: list }))
})

async function fetchFlags() {
    loadingFlags.value = true
    flagsError.value = ''
    try {
        const res = await fetch(`/api/geodata/flags?status=${encodeURIComponent(statusFilter.value)}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            flagsError.value = data.error || `Could not load flags (HTTP ${res.status}).`
            return
        }
        flags.value = Array.isArray(data.flags) ? data.flags : []
        flagsTotal.value = typeof data.total === 'number' ? data.total : flags.value.length
        flagsTruncated.value = data.truncated === true
        if (data.counts) {
            counts.value = data.counts
            emit('counts', data.counts)
        }
    } catch (e) {
        flagsError.value = String(e?.message || e)
    } finally {
        loadingFlags.value = false
    }
}

function setStatusFilter(s) {
    if (statusFilter.value === s) return
    statusFilter.value = s
    expandedId.value = null
    fetchFlags()
}

function toggleExpanded(id) {
    expandedId.value = expandedId.value === id ? null : id
}

// ─── Scan orchestration ─────────────────────────────────────────────────────

const scanRunning = computed(() => !!scanStatus.value?.running)

async function fetchScanStatus() {
    try {
        const res = await fetch('/api/geodata/scan/status', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) return
        const wasRunning = scanRunning.value
        scanStatus.value = await res.json()
        // Scan just finished → stop polling and pull the fresh flag set.
        if (wasRunning && !scanRunning.value) {
            stopScanPolling()
            fetchFlags()
        }
    } catch (e) {
        // swallow — poll retries
    }
}

function startScanPolling() {
    stopScanPolling()
    scanPollTimer = setInterval(fetchScanStatus, 2000)
}
function stopScanPolling() {
    if (scanPollTimer) clearInterval(scanPollTimer)
    scanPollTimer = null
}

async function runScan() {
    if (scanBusy.value || scanRunning.value) return
    scanBusy.value = true
    scanError.value = ''
    try {
        const res = await csrfFetch('/api/geodata/scan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            scanError.value = data.error || `Scan failed to start (HTTP ${res.status}).`
            return
        }
        // Mark running optimistically so the button disables before the
        // first status poll lands.
        scanStatus.value = { ...(scanStatus.value || {}), running: true, started_at: new Date().toISOString() }
        startScanPolling()
    } catch (e) {
        scanError.value = String(e?.message || e)
    } finally {
        scanBusy.value = false
    }
}

// ─── Repairs log ────────────────────────────────────────────────────────────

async function fetchRepairs() {
    loadingRepairs.value = true
    repairsError.value = ''
    try {
        const res = await fetch('/api/geodata/repairs', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            repairsError.value = data.error || `Could not load repairs (HTTP ${res.status}).`
            return
        }
        repairs.value = Array.isArray(data.repairs) ? data.repairs : []
    } catch (e) {
        repairsError.value = String(e?.message || e)
    } finally {
        loadingRepairs.value = false
    }
}

function switchToRepairs() {
    tab.value = 'repairs'
    fetchRepairs()
}

async function revertRepair(repair) {
    if (revertBusyId.value) return
    revertBusyId.value = repair.id
    revertError.value = ''
    revertErrorId.value = null
    try {
        const res = await csrfFetch(`/api/geodata/repairs/${repair.id}/revert`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            revertError.value = data.error || `Revert failed (HTTP ${res.status}).`
            revertErrorId.value = repair.id
            return
        }
        if (data.counts) {
            counts.value = data.counts
            emit('counts', data.counts)
        }
        // A revert can reopen the linked flag — refresh both lists.
        await Promise.all([fetchRepairs(), fetchFlags()])
    } catch (e) {
        revertError.value = String(e?.message || e)
        revertErrorId.value = repair.id
    } finally {
        revertBusyId.value = null
    }
}

// ─── Modal wiring ───────────────────────────────────────────────────────────

function openModal(mode, flag) {
    modal.value = { mode, flag }
}

function onRepairApplied(data) {
    modal.value = null
    if (data?.counts) {
        counts.value = data.counts
        emit('counts', data.counts)
    }
    // The flag list changed (resolved flag) and the repairs log grew.
    fetchFlags()
    if (tab.value === 'repairs') fetchRepairs()
}

// ─── Lifecycle ──────────────────────────────────────────────────────────────

onMounted(async () => {
    await fetchFlags()
    // If a scan was already running when the page loaded (e.g. kicked off
    // from another tab or the artisan command), resume polling.
    await fetchScanStatus()
    if (scanRunning.value) startScanPolling()
})
onBeforeUnmount(stopScanPolling)

// Parent (Show.vue) can force a refresh after reopen-maps etc.
defineExpose({ refresh: fetchFlags })
</script>
