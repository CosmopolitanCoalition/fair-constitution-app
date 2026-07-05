<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import LiveProgress from '@/Components/Setup/LiveProgress.vue'
import ReviewIssuesSection from '@/Components/Setup/ReviewIssuesSection.vue'
import { csrfFetch } from '@/lib/csrf'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

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
const customDataRoot       = ref('')         // P.8 — operator-supplied container path when source='folder'
const optFresh            = ref(false)
const optSkipPopulation   = ref(false)
// Renamed from optStopOnException — the new pause-and-ask behaviour replaces
// the legacy hard-halt. Backend still accepts the legacy field name on submit.
const optPauseOnException = ref(false)
const optCountries        = ref('')         // comma-separated ISO3, empty = all
const includeDebug        = ref(false)
const advancing           = ref(false)
const apportioning        = ref(false)
const advanceError        = ref('')
const submitting          = ref(false)
const submitError         = ref('')

// ─── Detected sources (GET step2/sources) ───────────────────────────────────
// The REAL container-path inventory, so the operator sees what the ETL will
// actually read — not a hardcoded host-path label that lies when the mount
// differs.
const sourcesLoading   = ref(true)
const sourcesError     = ref('')
const sources          = ref(null)   // full response from step2/sources

// ─── Local-folder override (POST step2/archive-path) ─────────────────────────
const archivePathInput   = ref('')   // host folder, e.g. D:\fair-constitution-map-files
const protomapsPathInput = ref('')   // optional host folder for basemap tiles
const savingArchivePath  = ref(false)
const archivePathError   = ref('')
const archivePathMessage = ref('')   // success message from the backend

// ─── Download picker (source='download') ─────────────────────────────────────
const downloadGeoboundaries = ref(true)
const downloadWorldpop      = ref(false)

let pollTimer = null

// ─── Helpers ────────────────────────────────────────────────────────────────

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

// Parse the comma/space-separated ISO3 box into a clean, validated list.
const parsedCountries = computed(() =>
    optCountries.value
        .split(/[\s,]+/)
        .map(s => s.trim().toUpperCase())
        .filter(s => /^[A-Z]{3}$/.test(s))
)

async function fetchSources() {
    sourcesLoading.value = true
    sourcesError.value = ''
    try {
        const res = await fetch('/api/setup/wizard/step2/sources', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) {
            sourcesError.value = `Could not read your map data (HTTP ${res.status}).`
            return
        }
        sources.value = await res.json()
    } catch (e) {
        sourcesError.value = 'Could not read your map data. ' + String(e)
    } finally {
        sourcesLoading.value = false
    }
}

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
            // A finished run may have changed what's on disk (a download run
            // populated /archive). Refresh the detected-data panel once.
            fetchSources()
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

// Save the local host folder(s) into .env via the backend. Needs a
// container restart (docker compose up -d) to take effect, which the
// backend tells us via the returned message.
async function saveArchivePath() {
    archivePathError.value = ''
    archivePathMessage.value = ''
    const archive = archivePathInput.value.trim()
    const protomaps = protomapsPathInput.value.trim()
    if (archive === '' && protomaps === '') {
        archivePathError.value = 'Enter at least one folder path.'
        return
    }
    savingArchivePath.value = true
    try {
        const res = await csrfFetch('/api/setup/wizard/step2/archive-path', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                archive_path:   archive || null,
                protomaps_path: protomaps || null,
            }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            archivePathError.value = data.error || `Could not save the folder (HTTP ${res.status}).`
            return
        }
        archivePathMessage.value = data.message
            || 'Saved. Re-run docker compose up -d, then reload this page.'
    } catch (e) {
        archivePathError.value = e.message || String(e)
    } finally {
        savingArchivePath.value = false
    }
}

async function submitRun() {
    submitError.value = ''

    // Guard the two source-specific requirements client-side so the operator
    // gets an immediate, plain message rather than a round-trip 422.
    if (source.value === 'download') {
        if (!downloadGeoboundaries.value && !downloadWorldpop.value) {
            submitError.value = 'Choose at least one dataset to download (boundaries and/or population).'
            return
        }
        if (parsedCountries.value.length === 0) {
            submitError.value = 'A download needs a country scope. Enter one or more ISO3 codes below (e.g. NZL,USA).'
            return
        }
    }
    if (source.value === 'folder' && customDataRoot.value.trim() === '') {
        submitError.value = 'Enter the container path to ingest (e.g. /archive/snapshots/2026-05).'
        return
    }

    submitting.value = true
    try {
        const downloadDatasets = []
        if (source.value === 'download') {
            if (downloadGeoboundaries.value) downloadDatasets.push('geoboundaries')
            if (downloadWorldpop.value)      downloadDatasets.push('worldpop')
        }

        const body = {
            source:              source.value,
            data_root:           source.value === 'folder' ? customDataRoot.value.trim() : null,
            download_datasets:   downloadDatasets,
            fresh:               optFresh.value,
            skip_population:     optSkipPopulation.value,
            pause_on_exception:  optPauseOnException.value,
            countries:           parsedCountries.value,
        }

        const res = await csrfFetch('/api/setup/wizard/step2/start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
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
        submitError.value = e.message || String(e)
    } finally {
        submitting.value = false
    }
}

async function sendControl(action) {
    try {
        const res = await csrfFetch('/api/setup/wizard/step2/control', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action }),
        })
        if (!res.ok) {
            const data = await res.json().catch(() => ({}))
            submitError.value = data.error || `Control '${action}' failed (HTTP ${res.status}).`
        }
        // Refresh so pendingControl/paused flip quickly.
        await fetchProgress()
    } catch (e) {
        submitError.value = e.message || String(e)
    }
}

// Export + restore-from-backup panels both moved out of Step 2:
//   - Export now lives on Step 4 (Confirm) — by the time the operator is
//     there the full state graph is populated, so an export captures
//     everything in one shot. Future admin sections will mount the same
//     ExportBackupPanel component.
//   - Restore-from-backup is a setup-process entry point and now lives
//     only on Step 0 — operator-initiated restore that lands them at the
//     step matching the bundle's setup_step_completed.
// Step 2 keeps its ETL controls and the Continue → apportionment button,
// nothing more.

async function advance() {
    advancing.value = true
    apportioning.value = true
    try {
        const res = await csrfFetch('/api/setup/wizard/step1/activate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        const data = await res.json().catch(() => ({}))
        if (res.ok) {
            router.visit(data.next || '/setup/step/3')
        } else {
            // Surface the real failure (apportionment 422/500, or csrfFetch's
            // honest 419 message) instead of a silent dead button.
            advanceError.value = data.error || data.message || `Could not continue (HTTP ${res.status}).`
        }
    } catch (e) {
        advanceError.value = e.message || 'Network error while continuing to districting.'
    } finally {
        advancing.value = false
        apportioning.value = false
    }
}

// ─── Derived UI state ───────────────────────────────────────────────────────

const canAdvance = computed(() => counts.value.adm0 > 0 && counts.value.adm1 > 0)
const isRunning  = computed(() => lifecycle.value === 'running')
const runOptionsDisabled = computed(() => isRunning.value || submitting.value)

// Label for the primary "Start" button — download runs read differently.
const startButtonLabel = computed(() => {
    if (submitting.value) return 'Submitting…'
    if (isRunning.value)  return 'Run in progress…'
    if (source.value === 'download') return 'Download + Ingest'
    return 'Start ETL Run'
})

// ─── Lifecycle ──────────────────────────────────────────────────────────────

onMounted(() => {
    fetchSources()
    startPolling()
})
onBeforeUnmount(stopPolling)
</script>

<template>
    <div class="max-w-5xl mx-auto px-6 py-8 w-full">
            <SetupStepper :current="2" :completed="settings.setup_step_completed" />

            <header class="mt-8 mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    Load Boundaries + Population Data
                </h1>
                <p class="text-gray-400 text-sm">
                    Point at your map data, then run the ETL pipeline. Progress streams live from the
                    ETL container. Once the run completes, review any flagged discrepancies before
                    continuing to districting.
                </p>
            </header>

            <!-- Your map data (detected inventory at the REAL container path) -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
                <div class="flex items-baseline justify-between mb-1">
                    <h2 class="text-white font-semibold">Your map data</h2>
                    <button
                        type="button"
                        @click="fetchSources"
                        :disabled="sourcesLoading"
                        class="text-xs text-gray-400 hover:text-gray-200 disabled:opacity-50"
                    >
                        {{ sourcesLoading ? 'Checking…' : 'Re-check ↻' }}
                    </button>
                </div>
                <p class="text-gray-500 text-xs mb-4">
                    The ETL reads from the container path
                    <code class="text-emerald-300">{{ sources?.archive_mount || '/archive' }}</code>,
                    which your <code>.env</code> maps from a folder on your computer
                    (<code class="text-sky-300">ARCHIVE_PATH</code>). If nothing is detected below,
                    the mount is empty or pointed at the wrong folder — set your local folder
                    or download the data further down.
                </p>

                <div v-if="sourcesError" class="mb-3 text-sm text-red-400">{{ sourcesError }}</div>

                <div v-if="sourcesLoading && !sources" class="text-gray-500 text-sm italic">
                    Reading the archive mount…
                </div>

                <template v-else-if="sources">
                    <div
                        v-if="!sources.archive_present"
                        class="mb-4 rounded-md border border-amber-800/70 bg-amber-900/20 px-3 py-2 text-amber-200 text-xs"
                    >
                        The archive mount <code>{{ sources.archive_mount }}</code> is not present in
                        the ETL container. Set your local folder below (then restart), or download the
                        data from the official sources.
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <!-- geoBoundaries -->
                        <div class="rounded-md border border-gray-800 bg-gray-950/60 p-3">
                            <div class="flex items-center gap-2 mb-1">
                                <span
                                    class="inline-block w-2 h-2 rounded-full"
                                    :class="sources.datasets.geoboundaries.present ? 'bg-emerald-400' : 'bg-gray-600'"
                                ></span>
                                <span class="text-white text-sm font-semibold">
                                    {{ sources.datasets.geoboundaries.label }}
                                </span>
                            </div>
                            <div class="text-xs" :class="sources.datasets.geoboundaries.present ? 'text-emerald-300' : 'text-gray-500'">
                                <template v-if="sources.datasets.geoboundaries.present">
                                    {{ sources.datasets.geoboundaries.countries }} countries detected
                                </template>
                                <template v-else>Not detected</template>
                            </div>
                            <div class="text-gray-600 text-[11px] font-mono mt-1 break-all">
                                {{ sources.datasets.geoboundaries.path }}
                            </div>
                        </div>

                        <!-- WorldPop -->
                        <div class="rounded-md border border-gray-800 bg-gray-950/60 p-3">
                            <div class="flex items-center gap-2 mb-1">
                                <span
                                    class="inline-block w-2 h-2 rounded-full"
                                    :class="sources.datasets.worldpop.present ? 'bg-emerald-400' : 'bg-gray-600'"
                                ></span>
                                <span class="text-white text-sm font-semibold">
                                    {{ sources.datasets.worldpop.label }}
                                </span>
                            </div>
                            <div class="text-xs" :class="sources.datasets.worldpop.present ? 'text-emerald-300' : 'text-gray-500'">
                                <template v-if="sources.datasets.worldpop.present">
                                    {{ sources.datasets.worldpop.countries }} countries detected
                                </template>
                                <template v-else>Not detected</template>
                            </div>
                            <div class="text-gray-600 text-[11px] font-mono mt-1 break-all">
                                {{ sources.datasets.worldpop.path }}
                            </div>
                        </div>

                        <!-- Protomaps -->
                        <div class="rounded-md border border-gray-800 bg-gray-950/60 p-3">
                            <div class="flex items-center gap-2 mb-1">
                                <span
                                    class="inline-block w-2 h-2 rounded-full"
                                    :class="sources.datasets.protomaps.present ? 'bg-emerald-400' : 'bg-gray-600'"
                                ></span>
                                <span class="text-white text-sm font-semibold">
                                    {{ sources.datasets.protomaps.label }}
                                </span>
                            </div>
                            <div class="text-xs" :class="sources.datasets.protomaps.present ? 'text-emerald-300' : 'text-gray-500'">
                                <template v-if="sources.datasets.protomaps.present">
                                    {{ sources.datasets.protomaps.files.length }} basemap file(s)
                                </template>
                                <template v-else>Not detected (optional)</template>
                            </div>
                            <div class="text-gray-600 text-[11px] font-mono mt-1 break-all">
                                {{ sources.datasets.protomaps.path }}
                            </div>
                        </div>
                    </div>
                </template>

                <!-- LOCAL FOLDER override -->
                <div class="mt-5 pt-5 border-t border-gray-800">
                    <h3 class="text-white text-sm font-semibold mb-1">Point at a local folder</h3>
                    <p class="text-gray-500 text-xs mb-3">
                        If your map files live somewhere else on this computer, enter that folder here.
                        It's written to <code class="text-sky-300">ARCHIVE_PATH</code> in your
                        <code>.env</code> so the container remounts <code>/archive</code> from it.
                        On Windows, use your host path (e.g.
                        <code class="text-emerald-300">D:\fair-constitution-map-files</code>).
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-gray-300 text-xs">Map data folder (ARCHIVE_PATH)</span>
                            <input
                                type="text"
                                v-model="archivePathInput"
                                placeholder="D:\fair-constitution-map-files"
                                class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                       text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none"
                            />
                        </label>
                        <label class="block">
                            <span class="text-gray-300 text-xs">Basemap tiles folder (PROTOMAPS_DIR, optional)</span>
                            <input
                                type="text"
                                v-model="protomapsPathInput"
                                placeholder="D:\fair-constitution-map-files\protomaps"
                                class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                       text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none"
                            />
                        </label>
                    </div>

                    <div class="mt-3 flex items-center gap-3">
                        <button
                            type="button"
                            @click="saveArchivePath"
                            :disabled="savingArchivePath"
                            class="bg-gray-700 hover:bg-gray-600 disabled:bg-gray-800 text-white px-4 py-1.5 rounded-md text-sm font-semibold transition-colors"
                        >
                            {{ savingArchivePath ? 'Saving…' : 'Save folder path' }}
                        </button>
                        <span v-if="archivePathError" class="text-red-400 text-xs">{{ archivePathError }}</span>
                    </div>
                    <div
                        v-if="archivePathMessage"
                        class="mt-3 rounded-md border border-emerald-800/70 bg-emerald-900/20 px-3 py-2 text-emerald-200 text-xs"
                    >
                        {{ archivePathMessage }}
                    </div>
                </div>
            </section>

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
                                Ingest whatever is detected above at the container path
                                <code class="text-emerald-300">{{ sources?.archive_mount || '/archive' }}</code>
                                (mapped from your <code class="text-sky-300">ARCHIVE_PATH</code> folder).
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
                                Ingest a specific <em>container</em> path — an alternate snapshot or a
                                sub-directory of <code>/archive</code>. (To point at a different folder
                                on your computer, use "Point at a local folder" above instead.)
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

                    <!-- Download from official sources (now wired) -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-md border cursor-pointer transition-colors"
                        :class="source === 'download' ? 'border-blue-500 bg-blue-900/20' : 'border-gray-800 hover:border-gray-700'"
                    >
                        <input type="radio" value="download" v-model="source"
                               class="mt-1" :disabled="runOptionsDisabled" />
                        <div class="flex-1">
                            <div class="text-white font-semibold text-sm">Download from official sources</div>
                            <div class="text-gray-400 text-xs mt-1">
                                Fetch the open datasets straight from their official repos, country by
                                country, then ingest. Requires a country scope below.
                            </div>

                            <div v-if="source === 'download'" class="mt-3 space-y-2">
                                <label class="flex items-start gap-2 text-gray-200 text-xs">
                                    <input type="checkbox" v-model="downloadGeoboundaries"
                                           :disabled="runOptionsDisabled" class="mt-0.5" />
                                    <span>
                                        Jurisdiction boundaries — <strong>geoBoundaries</strong>
                                        <span class="block text-gray-500">github.com/wmgeolab/geoBoundaries (CC BY 4.0)</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2 text-gray-200 text-xs">
                                    <input type="checkbox" v-model="downloadWorldpop"
                                           :disabled="runOptionsDisabled" class="mt-0.5" />
                                    <span>
                                        Population — <strong>WorldPop</strong>
                                        <span class="block text-gray-500">
                                            data.worldpop.org (CC BY 4.0) · pulls boundaries too (needed to attribute population)
                                        </span>
                                    </span>
                                </label>
                                <p class="text-amber-300/80 text-[11px] italic">
                                    Country scope is required for a download — set the ISO3 list in Run Options below.
                                    Basemap tiles (Protomaps) are supplied separately, not via this download.
                                </p>
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
                        <span>
                            Countries (ISO3, comma-separated<template v-if="source === 'download'">, required for download</template><template v-else>; empty = all</template>):
                        </span>
                        <input
                            type="text"
                            v-model="optCountries"
                            placeholder="NZL,USA"
                            class="flex-1 bg-gray-950 border rounded px-2 py-1 font-mono text-xs text-gray-100"
                            :class="source === 'download' && parsedCountries.length === 0 ? 'border-amber-600' : 'border-gray-800'"
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
                        {{ startButtonLabel }}
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
                    <span v-if="advanceError" class="text-xs text-red-400 max-w-sm text-right">
                        {{ advanceError }}
                    </span>
                </div>
            </div>
    </div>
</template>
