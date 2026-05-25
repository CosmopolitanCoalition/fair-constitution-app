<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import LiveProgress from '@/Components/Setup/LiveProgress.vue'
import ReviewIssuesSection from '@/Components/Setup/ReviewIssuesSection.vue'

const props = defineProps({
    step: { type: Number, required: true },
    settings: { type: Object, required: true },
})

// ─── Reactive state ─────────────────────────────────────────────────────────

const lifecycle           = ref('loading')   // loading | idle | running | done | failed
const running             = ref(null)
const done                = ref(null)
const failed              = ref(null)
const progress            = ref(null)
const current             = ref(null)
// Phase P.1 stacked-progress-bars state — written by the Python ETL via
// heartbeat.bar_start / bar_update / bar_complete / worldpop_advance_country
// and surfaced by SetupController::mapDataProgress as `bars`. Drives the
// new <StackedProgressBars /> panel inside <LiveProgress />.
const bars                = ref(null)
// Phase P.3 structured events — extracted by SetupController::extractEvents
// from `[EVT] {...}` markers in the ETL log. Drives <EventToasts /> for
// errors / warnings / info-level UI surfacing without operator scrolling
// the log. Each entry: { id, ts, level, type, msg, iso?, name?, adm_level?, phase? }
const events              = ref([])
// Non-null while the ETL is paused on a per-country error awaiting an
// operator decision (skip/retry/abort). Surfaced as a card by LiveProgress.
const errorPause          = ref(null)
// Data-quality review summary — populated by the backend when lifecycle
// ∈ {done, failed}. Null while running. Drives the ReviewIssuesSection
// post-ETL, BEFORE the operator clicks Continue (apportionment).
const review              = ref(null)

// Log buffer is accumulated client-side across polls so the "Show DEBUG"
// toggle filters the visible slice without throwing away history we've
// already received. The backend always returns the most recent ~120 lines
// (DEBUG and non-DEBUG) and we dedupe by exact-string match.
const LOG_BUFFER_CAP = 2000
const logBuffer           = ref([])
const seenLogLines        = new Set()

const counts              = ref({ adm0: 0, adm1: 0, adm2: 0, total: 0, by_level: [] })
const pendingControl      = ref({ halt: false, pause: false, resume: false })

const source              = ref('archive')  // archive | folder | download | upload
const customDataRoot       = ref('')         // P.8 — operator-supplied path when source='folder'
const optFresh            = ref(false)
const optSkipPopulation   = ref(false)
// Renamed from optStopOnException — the new pause-and-ask behaviour replaces
// the legacy hard-halt. Backend still accepts the legacy field name on submit.
const optPauseOnException = ref(false)
const optCountries        = ref('')         // comma-separated ISO3, empty = all
const includeDebug        = ref(false)
const advancing           = ref(false)
const apportioning        = ref(false)
const submitting          = ref(false)
const submitError         = ref('')

let pollTimer = null

// ─── Helpers ────────────────────────────────────────────────────────────────

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

function clearLogBuffer() {
    logBuffer.value = []
    seenLogLines.clear()
}

function appendLogLines(incoming) {
    if (!Array.isArray(incoming) || incoming.length === 0) return
    let appended = 0
    for (const line of incoming) {
        if (typeof line !== 'string' || line === '') continue
        if (seenLogLines.has(line)) continue
        seenLogLines.add(line)
        logBuffer.value.push(line)
        appended++
    }
    if (appended === 0) return
    // FIFO trim — drop oldest lines (and their seen-set entries) over cap.
    const overflow = logBuffer.value.length - LOG_BUFFER_CAP
    if (overflow > 0) {
        const dropped = logBuffer.value.splice(0, overflow)
        for (const d of dropped) seenLogLines.delete(d)
    }
}

// Client-side filter: hide DEBUG lines when the toggle is off. Filtering
// happens here, not on the backend, so the buffer is preserved on toggle.
const displayLines = computed(() => {
    if (includeDebug.value) return logBuffer.value
    return logBuffer.value.filter(l => !/\[DEBUG\s*\]/.test(l))
})

async function fetchProgress() {
    try {
        const qs = new URLSearchParams({ tail: '120' })
        const res = await fetch(`/api/setup/wizard/step2/progress?${qs.toString()}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) return
        const data = await res.json()
        // New run → fresh buffer. Done synchronously here (before
        // appendLogLines below) rather than via a Vue watcher to avoid
        // the watcher firing AFTER appendLogLines and wiping the lines
        // we just merged in.
        const wasRunning = lifecycle.value === 'running'
        const nowRunning = data.lifecycle === 'running'
        if (nowRunning && !wasRunning) clearLogBuffer()

        lifecycle.value      = data.lifecycle
        running.value        = data.running
        done.value           = data.done
        failed.value         = data.failed
        progress.value       = data.progress
        current.value        = data.current || null
        bars.value           = data.bars || null   // Phase P.1 stacked-bar state
        events.value         = Array.isArray(data.events) ? data.events : []   // P.3
        errorPause.value     = data.error_pause || null
        // The `review` block is no longer rendered in Step 2 — the legacy
        // inline ReviewIssuesSection moved into the Jurisdiction Viewer's
        // drill-down panels (see the comment above the "4. Review & Accept"
        // section in the template). We never ask the backend for ?include=review
        // from this page; the assignment below is kept defensive in case
        // future code re-enables it.
        if (data.review !== undefined && data.review !== null) {
            review.value = data.review
        }
        appendLogLines(Array.isArray(data.log_tail) ? data.log_tail : [])
        counts.value         = data.jurisdictions_counts || counts.value
        pendingControl.value = data.pending_control || { halt: false, pause: false, resume: false }

        // Stop polling immediately once the run terminates. Without this the
        // page keeps hammering the progress endpoint every 2s forever — and
        // each call still costs hundreds of ms to read progress.json + tail
        // the log + sum jurisdictions_counts. The terminal state is the
        // steady state — re-polling adds no value.
        if (lifecycle.value === 'done' || lifecycle.value === 'failed') {
            stopPolling()
        }
    } catch (e) {
        // swallow — poll will retry
    }
}

async function startPolling() {
    stopPolling()
    // Wait for the first fetch BEFORE arming the interval. If the response
    // shows we're already in a terminal state (the steady state for most
    // post-restart visits), don't arm at all — there's nothing to poll. The
    // interval will be re-armed by submitRun() if the operator starts a new
    // run from this page.
    await fetchProgress()
    if (lifecycle.value === 'done' || lifecycle.value === 'failed') return
    pollTimer = setInterval(fetchProgress, 2000)
}

function stopPolling() {
    if (pollTimer) clearInterval(pollTimer)
    pollTimer = null
}

async function submitRun() {
    submitError.value = ''
    submitting.value = true
    try {
        const parsedCountries = optCountries.value
            .split(/[\s,]+/)
            .map(s => s.trim().toUpperCase())
            .filter(s => /^[A-Z]{3}$/.test(s))

        const body = {
            source:              source.value,
            data_root:           source.value === 'folder' ? customDataRoot.value.trim() : null,
            fresh:               optFresh.value,
            skip_population:     optSkipPopulation.value,
            pause_on_exception:  optPauseOnException.value,
            countries:           parsedCountries,
        }

        const res = await fetch('/api/setup/wizard/step2/start', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(body),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            submitError.value = data.error || `Run submission failed (HTTP ${res.status}).`
            return
        }
        // Immediately refresh state so UI flips to "running" before the next tick.
        // Also re-arm polling — startPolling() may have exited without arming
        // the interval if the previous lifecycle was terminal.
        await startPolling()
    } catch (e) {
        submitError.value = String(e)
    } finally {
        submitting.value = false
    }
}

async function sendControl(action) {
    try {
        const res = await fetch('/api/setup/wizard/step2/control', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ action }),
        })
        if (!res.ok) {
            const data = await res.json().catch(() => ({}))
            submitError.value = data.error || `Control '${action}' failed (HTTP ${res.status}).`
        }
        // Refresh so pendingControl/paused flip quickly.
        await fetchProgress()
    } catch (e) {
        submitError.value = String(e)
    }
}

// ── P.9 — async export panel ──────────────────────────────────────────────
const exportStarting = ref(false)
const exportsList    = ref([])
const exportError    = ref('')
let exportsPollTimer = null

async function refreshExports() {
    try {
        const res = await fetch('/api/export/jurisdictions/list', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) return
        const data = await res.json()
        exportsList.value = Array.isArray(data.exports) ? data.exports : []
    } catch (e) {
        // silent — poll retries
    }
}

async function startExport(skipRasters) {
    exportStarting.value = true
    exportError.value    = ''
    try {
        const url = `/api/export/jurisdictions?async=1${skipRasters ? '&skip_rasters=1' : ''}`
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            exportError.value = data.error || `export start failed (HTTP ${res.status})`
            return
        }
        await refreshExports()
    } catch (e) {
        exportError.value = String(e?.message || e)
    } finally {
        exportStarting.value = false
    }
}

async function deleteExport(exportId) {
    try {
        await fetch(`/api/export/jurisdictions/${exportId}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
        })
        await refreshExports()
    } catch (e) { /* ignore */ }
}

function formatBytes(n) {
    if (!n || n < 0) return ''
    if (n < 1024)             return `${n} B`
    if (n < 1024 * 1024)      return `${(n / 1024).toFixed(1)} KB`
    if (n < 1024 ** 3)        return `${(n / 1024 / 1024).toFixed(1)} MB`
    return `${(n / 1024 ** 3).toFixed(2)} GB`
}

function formatRelative(iso) {
    if (!iso) return ''
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return iso
    const sec = Math.round((Date.now() - d.getTime()) / 1000)
    if (sec < 0)        return d.toLocaleTimeString()
    if (sec < 60)       return `${sec}s ago`
    if (sec < 3600)     return `${Math.floor(sec / 60)}m ago`
    if (sec < 86400)    return `${Math.floor(sec / 3600)}h ago`
    return d.toLocaleString()
}

// Poll the export listing while any export is "running". Stops when nothing
// is running to avoid hammering the listing endpoint.
function ensureExportPolling() {
    const anyRunning = exportsList.value.some(e => e.status === 'running')
    if (anyRunning && !exportsPollTimer) {
        exportsPollTimer = setInterval(refreshExports, 5000)
    } else if (!anyRunning && exportsPollTimer) {
        clearInterval(exportsPollTimer)
        exportsPollTimer = null
    }
}
watch(exportsList, ensureExportPolling, { deep: true })
onMounted(() => {
    refreshExports()
})
onBeforeUnmount(() => {
    if (exportsPollTimer) { clearInterval(exportsPollTimer); exportsPollTimer = null }
})

async function advance() {
    advancing.value = true
    apportioning.value = true
    try {
        const res = await fetch('/api/setup/wizard/step1/activate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({}),
        })
        const data = await res.json().catch(() => ({}))
        if (res.ok) router.visit(data.next || '/setup/step/3')
    } catch (e) {
        // no-op
    } finally {
        advancing.value = false
        apportioning.value = false
    }
}

// ─── Derived UI state ───────────────────────────────────────────────────────

const canAdvance = computed(() => counts.value.adm0 > 0 && counts.value.adm1 > 0)
const isRunning  = computed(() => lifecycle.value === 'running')
const runOptionsDisabled = computed(() => isRunning.value || submitting.value)

// ─── Lifecycle ──────────────────────────────────────────────────────────────

onMounted(startPolling)
onBeforeUnmount(stopPolling)
</script>

<template>
    <AppLayout :hide-nav="true">
        <div class="max-w-5xl mx-auto px-6 py-8 w-full">
            <SetupStepper :current="2" :completed="settings.setup_step_completed" />

            <header class="mt-8 mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    Load Boundaries + Population Data
                </h1>
                <p class="text-gray-400 text-sm">
                    Pick a data source and run the ETL pipeline. Progress streams live from the
                    ETL container. Once the run completes, review any flagged discrepancies before
                    continuing to districting.
                </p>
            </header>

            <!-- Source Picker -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
                <h2 class="text-white font-semibold mb-4">1. Data Source</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Local Archive (default) — bind-mounted /archive folder -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-md border cursor-pointer transition-colors"
                        :class="source === 'archive' ? 'border-blue-500 bg-blue-900/20' : 'border-gray-800 hover:border-gray-700'"
                    >
                        <input type="radio" value="archive" v-model="source"
                               class="mt-1" :disabled="runOptionsDisabled" />
                        <div class="flex-1">
                            <div class="text-white font-semibold text-sm">Local Archive (default)</div>
                            <div class="text-gray-400 text-xs mt-1">
                                Reads from <code class="text-emerald-300">D:\fair-constitution-map-files</code>
                                (bind-mounted into the ETL container at <code>/archive</code>).
                                Fastest path — no network.
                            </div>
                        </div>
                    </label>

                    <!-- Custom Folder (P.8) — operator-supplied container path -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-md border cursor-pointer transition-colors"
                        :class="source === 'folder' ? 'border-blue-500 bg-blue-900/20' : 'border-gray-800 hover:border-gray-700'"
                    >
                        <input type="radio" value="folder" v-model="source"
                               class="mt-1" :disabled="runOptionsDisabled" />
                        <div class="flex-1">
                            <div class="text-white font-semibold text-sm">Custom Folder</div>
                            <div class="text-gray-400 text-xs mt-1">
                                Use a different folder visible to the ETL container
                                (e.g. an alternate snapshot or a sub-directory of
                                <code>/archive</code>).
                            </div>
                            <input v-if="source === 'folder'"
                                   type="text"
                                   v-model="customDataRoot"
                                   placeholder="/archive/snapshots/2026-05"
                                   :disabled="runOptionsDisabled"
                                   class="mt-2 w-full px-2 py-1 rounded bg-gray-950 border border-gray-700
                                          text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none" />
                        </div>
                    </label>

                    <!-- URL Download (placeholder for future) -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-md border cursor-not-allowed opacity-60"
                        :class="source === 'download' ? 'border-blue-500 bg-blue-900/20' : 'border-gray-800'"
                    >
                        <input type="radio" value="download" v-model="source"
                               class="mt-1" disabled />
                        <div class="flex-1">
                            <div class="text-white font-semibold text-sm">Custom URL (planned)</div>
                            <div class="text-gray-400 text-xs mt-1">
                                Fetch a geoBoundaries / WorldPop archive from a URL
                                (e.g. the official repos at
                                <code>github.com/wmgeolab/geoBoundaries</code> /
                                <code>data.worldpop.org</code>). Backend pre-fetch
                                handler not yet wired.
                            </div>
                        </div>
                    </label>

                    <!-- Browser Upload (placeholder for future) -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-md border cursor-not-allowed opacity-60"
                        :class="source === 'upload' ? 'border-blue-500 bg-blue-900/20' : 'border-gray-800'"
                    >
                        <input type="radio" value="upload" v-model="source"
                               class="mt-1" disabled />
                        <div class="flex-1">
                            <div class="text-white font-semibold text-sm">Browser Upload (planned)</div>
                            <div class="text-gray-400 text-xs mt-1">
                                Upload a tarball or folder from this browser. Multipart
                                upload handler not yet wired.
                            </div>
                        </div>
                    </label>
                </div>
            </section>

            <!-- Run Options -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
                <h2 class="text-white font-semibold mb-4">2. Run Options</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <label class="flex items-center gap-2 text-gray-200">
                        <input type="checkbox" v-model="optFresh" :disabled="runOptionsDisabled" />
                        <span>Fresh (purge existing rows first)</span>
                    </label>
                    <label class="flex items-center gap-2 text-gray-200">
                        <input type="checkbox" v-model="optSkipPopulation" :disabled="runOptionsDisabled" />
                        <span>Skip Phase 2 (Population)</span>
                    </label>
                    <label class="flex items-start gap-2 text-gray-400">
                        <input type="checkbox" v-model="optPauseOnException" :disabled="runOptionsDisabled" class="mt-1" />
                        <span>
                            Pause on first exception
                            <span class="block text-xs text-gray-500 italic">
                                When any country errors, pause the run and let you skip that country,
                                retry, or abort. Without this, the run logs the error and continues.
                            </span>
                        </span>
                    </label>
                    <label class="flex items-center gap-2 text-gray-200">
                        <span>Only countries (ISO3, comma-separated; empty = all):</span>
                        <input
                            type="text"
                            v-model="optCountries"
                            placeholder="NZL,USA"
                            class="flex-1 bg-gray-950 border border-gray-800 rounded px-2 py-1 font-mono text-xs text-gray-100"
                            :disabled="runOptionsDisabled"
                        />
                    </label>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button
                        type="button"
                        @click="submitRun"
                        :disabled="runOptionsDisabled"
                        class="bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 text-white px-5 py-2 rounded-md font-semibold transition-colors"
                    >
                        {{ submitting ? 'Submitting…' : (isRunning ? 'Run in progress…' : 'Start ETL Run') }}
                    </button>
                    <span v-if="submitError" class="text-red-400 text-sm">{{ submitError }}</span>
                </div>
            </section>

            <!-- Live Progress -->
            <LiveProgress
                :lifecycle="lifecycle"
                :running="running"
                :done="done"
                :failed="failed"
                :progress="progress"
                :current="current"
                :bars="bars"
                :events="events"
                :error-pause="errorPause"
                :counts="counts"
                :log-tail="displayLines"
                :pending-control="pendingControl"
                v-model:include-debug="includeDebug"
                @control="sendControl"
            />

            <!-- 4. Review & Accept — post-ETL the operator inspects the imported
                 jurisdictions in the dedicated viewer (where map + stats + raster
                 overlay are all available together), then comes back here to
                 click Continue. Continue triggers apportionment.
                 P.1.1: the legacy inline ReviewIssuesSection moved into the
                 viewer's drill-down panels; Step 2 now just links over. -->
            <section v-if="lifecycle === 'done'" class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
                <div class="flex items-baseline justify-between mb-3">
                    <h2 class="text-white font-semibold">4. Review & Accept</h2>
                </div>
                <p class="text-gray-400 text-xs mb-3">
                    The import finished. Open the jurisdiction viewer to inspect
                    boundaries, populations, raster overlays, dual-footprint
                    relationships, and any flagged discrepancies. Click Continue
                    below when satisfied — that triggers apportionment.
                </p>
                <a href="/jurisdictions"
                   class="inline-flex items-center gap-2 px-3 py-1.5 rounded border bg-blue-900/40 border-blue-700 text-blue-200 hover:bg-blue-900/70 text-sm">
                    Open Jurisdiction Viewer →
                </a>

                <!-- P.9 — Export current data as a portable tarball after the
                     ETL has succeeded. Two modes:
                       Full   → jurisdictions + populations + worldpop_rasters
                                + meta + settings (~7 GB at world scale).
                       No rasters → everything except worldpop_rasters
                                (~tens of MB; receiving instance re-runs
                                WorldPop).
                     Both run async via Horizon (large pg_dump would time out
                     a sync HTTP request). Status panel below polls and shows
                     in-progress + completed builds with download links. -->
                <div v-if="lifecycle === 'done'" class="mt-4 pt-4 border-t border-gray-800 space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-gray-400">Export current data:</span>
                        <button type="button"
                                @click="startExport(false)"
                                :disabled="exportStarting"
                                class="text-xs px-3 py-1.5 rounded border bg-blue-900/40 border-blue-700 text-blue-200 hover:bg-blue-900/70 disabled:opacity-50">
                            Full (with rasters)
                        </button>
                        <button type="button"
                                @click="startExport(true)"
                                :disabled="exportStarting"
                                class="text-xs px-3 py-1.5 rounded border bg-gray-800 border-gray-700 text-gray-200 hover:bg-gray-700 disabled:opacity-50">
                            Without rasters
                        </button>
                        <span class="text-[11px] text-gray-500">
                            jurisdictions + populations + meta + settings; restore at Step 0
                        </span>
                    </div>

                    <!-- In-progress + completed list -->
                    <div v-if="exportsList.length" class="rounded border border-gray-800 bg-gray-950/40">
                        <div class="px-3 py-2 text-xs text-gray-400 border-b border-gray-800">
                            Recent exports
                        </div>
                        <div class="divide-y divide-gray-800">
                            <div v-for="e in exportsList" :key="e.export_id"
                                 class="px-3 py-2 text-xs flex items-center justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <div class="font-mono text-gray-300 truncate">{{ e.export_id }}</div>
                                    <div class="text-[10px] text-gray-500">
                                        {{ e.skip_rasters ? 'no rasters · ' : 'full · ' }}
                                        started {{ formatRelative(e.started_at) }}
                                        <template v-if="e.completed_at">
                                            · finished {{ formatRelative(e.completed_at) }}
                                        </template>
                                        <template v-if="e.size_bytes">
                                            · {{ formatBytes(e.size_bytes) }}
                                        </template>
                                    </div>
                                    <div v-if="e.error" class="text-[10px] text-red-400 mt-0.5">{{ e.error }}</div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span v-if="e.status === 'running'"
                                          class="text-[10px] px-2 py-0.5 rounded bg-blue-900 text-blue-200 border border-blue-700">
                                        running
                                    </span>
                                    <span v-else-if="e.status === 'done'"
                                          class="text-[10px] px-2 py-0.5 rounded bg-emerald-900 text-emerald-200 border border-emerald-700">
                                        done
                                    </span>
                                    <span v-else-if="e.status === 'failed'"
                                          class="text-[10px] px-2 py-0.5 rounded bg-red-900 text-red-200 border border-red-700">
                                        failed
                                    </span>
                                    <span v-else
                                          class="text-[10px] px-2 py-0.5 rounded bg-gray-800 text-gray-400 border border-gray-700">
                                        {{ e.status }}
                                    </span>
                                    <a v-if="e.archive_filename"
                                       :href="`/api/export/jurisdictions/download/${e.archive_filename}`"
                                       class="text-[11px] text-blue-400 hover:text-blue-300">
                                        ⬇ download
                                    </a>
                                    <button v-if="e.status !== 'running'"
                                            type="button"
                                            @click="deleteExport(e.export_id)"
                                            class="text-[11px] text-gray-500 hover:text-red-400">
                                        delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="exportError" class="text-xs text-red-400">{{ exportError }}</div>
                </div>
            </section>

            <div class="flex justify-between pt-4 border-t border-gray-800 mt-4">
                <a href="/setup/step/1" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">
                    ← Back
                </a>
                <div class="flex flex-col items-end gap-1">
                    <button
                        type="button"
                        :disabled="advancing || !canAdvance || isRunning"
                        @click="advance"
                        class="bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 disabled:cursor-not-allowed text-white px-5 py-2 rounded-md font-semibold transition-colors"
                        :title="!canAdvance ? 'Load at least one nation (ADM1) before continuing' : ''"
                    >
                        {{ apportioning ? 'Sizing legislatures…' : (advancing ? 'Saving…' : 'Continue →') }}
                    </button>
                    <span v-if="apportioning" class="text-xs text-gray-500 italic">
                        Running cube-root apportionment across the jurisdiction tree…
                    </span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
