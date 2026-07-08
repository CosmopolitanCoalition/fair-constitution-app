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
// True when Continue 422'd on the map-acceptance gate (activateStep1 refuses
// until map_accepted_at is stamped in the Jurisdiction Viewer). Renders the
// message as amber guidance with a viewer link instead of a raw red error.
const acceptanceRequired  = ref(false)
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
const archivePathCommand = ref('')   // copy-pasteable recreate command from the backend

// ─── Download picker (source='download') ─────────────────────────────────────
const downloadGeoboundaries = ref(true)
const downloadWorldpop      = ref(false)
const downloadProtomaps     = ref(false)

// Dataset variants — sent only when the relevant dataset is selected. Defaults
// mirror the canonical archive layout (worldpop_100m_latest / gbOpen) so a
// plain download reproduces what a local archive would have contained.
const wpYear        = ref('2020')          // '2020' | '2023' | 'latest'
const wpVariant     = ref('constrained')   // 'constrained' | 'unconstrained'
const wpResolution  = ref('100m')          // '100m' | '1km'
const wpUnAdjusted  = ref(false)
const gbRelease     = ref('gbOpen')        // 'gbOpen' | 'gbHumanitarian' | 'gbAuthoritative'

let pollTimer = null

// ─── Geodata flag counts (post-ETL Review & Accept state) ────────────────────
// Once the ETL is done, Step 2 surfaces the open-flag count from the Data
// Review & Repair plane so the operator knows what awaits in the Jurisdiction
// Viewer. Polled gently (10s) — repairs happen over there, not here.
const flagState = ref({ loaded: false, open: 0, critical: 0, warning: 0, info: 0 })
let flagPollTimer = null

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
            // Post-ETL the Review & Accept card shows the live open-flag
            // count from the repair plane; keep it fresh while the operator
            // repairs over in the Jurisdiction Viewer.
            if (lifecycle.value === 'done' && !flagPollTimer) startFlagPolling()
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

async function fetchFlagCounts() {
    try {
        const res = await fetch('/api/geodata/flags?status=open', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) return   // best-effort — the viewer is the authoritative surface
        const data = await res.json().catch(() => ({}))
        const sev  = data.counts?.open_by_severity || {}
        flagState.value = {
            loaded:   true,
            open:     data.counts?.open ?? 0,
            critical: sev.critical ?? 0,
            warning:  sev.warning ?? 0,
            info:     sev.info ?? 0,
        }
    } catch (e) {
        // swallow — poll retries
    }
}

function startFlagPolling() {
    stopFlagPolling()
    fetchFlagCounts()
    // Once the map is accepted the repair window is closed and the counts
    // are frozen — one fetch for display is enough, no 10s heartbeat forever.
    // (Acceptance happens in the Jurisdiction Viewer, so returning to Step 2
    // is always a fresh page load carrying the updated settings prop.)
    if (props.settings?.map_accepted_at) return
    flagPollTimer = setInterval(fetchFlagCounts, 10000)
}

function stopFlagPolling() {
    if (flagPollTimer) clearInterval(flagPollTimer)
    flagPollTimer = null
}

// Save the local host folder(s) into .env via the backend. Needs a
// container restart (docker compose up -d) to take effect, which the
// backend tells us via the returned message.
async function saveArchivePath() {
    archivePathError.value = ''
    archivePathMessage.value = ''
    archivePathCommand.value = ''
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
        // Surface the backend's recreate command verbatim as a copy-pasteable
        // code block (it explicitly says up -d, not a stop/start).
        archivePathCommand.value = data.command || 'docker compose up -d'
        // The mount won't have changed yet — mark the detected panel as
        // apply-pending locally so the banner appears immediately without
        // waiting for the operator to click Re-check.
        if (sources.value) {
            sources.value.apply_pending = true
            if (archive) sources.value.archive_env_path = archive
        }
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
        if (!downloadGeoboundaries.value && !downloadWorldpop.value && !downloadProtomaps.value) {
            submitError.value = 'Choose at least one dataset to download (boundaries, population, and/or basemap tiles).'
            return
        }
        // Country scope is now OPTIONAL — an empty list downloads ALL countries
        // (a full-world pull). No client gate here; the UI warns about the size.
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
            if (downloadProtomaps.value)     downloadDatasets.push('protomaps')
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

        // Dataset variants — only relevant to a download, and only for the
        // datasets actually selected. Sent alongside so the downloader picks
        // the right WorldPop / geoBoundaries product.
        if (source.value === 'download') {
            if (downloadWorldpop.value) {
                // 'latest' is expressed by omitting the year (null) so the
                // downloader falls back to its newest-available default.
                body.wp_year        = wpYear.value === 'latest' ? null : Number(wpYear.value)
                body.wp_variant     = wpVariant.value
                body.wp_resolution  = wpResolution.value
                body.wp_un_adjusted = wpUnAdjusted.value
            }
            if (downloadGeoboundaries.value || downloadWorldpop.value) {
                // WorldPop drags boundaries along, so a gb_release is relevant
                // whenever either boundary-bearing dataset is selected.
                body.gb_release = gbRelease.value
            }
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
    acceptanceRequired.value = false
    advanceError.value = ''
    try {
        const res = await csrfFetch('/api/setup/wizard/step1/activate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        const data = await res.json().catch(() => ({}))
        if (res.ok) {
            router.visit(data.next || '/setup/step/3')
        } else if (res.status === 422 && (data.map_acceptance_required || /accept the map data/i.test(data.error || ''))) {
            // Map-acceptance gate: activateStep1 refuses until the operator
            // accepts the map data in the Jurisdiction Viewer. Render as
            // guidance (amber + link), not a raw error.
            acceptanceRequired.value = true
            advanceError.value = data.error
            fetchFlagCounts()
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
onBeforeUnmount(() => {
    stopPolling()
    stopFlagPolling()
})
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

                <!-- Half-applied archive: .env points at a real folder but the
                     containers haven't been recreated, so /archive is still
                     empty. This is the #1 "the archive won't take" trap — the
                     operator ran stop/start (or Docker Desktop restart), which
                     reuses the old mount. Recreation via `up -d` is required. -->
                <div
                    v-if="sources?.apply_pending"
                    class="mb-4 rounded-md border border-amber-600 bg-amber-900/30 px-4 py-3 text-amber-100 text-sm"
                >
                    <div class="flex items-start gap-2">
                        <span class="text-amber-300 text-base leading-none mt-0.5">⚠</span>
                        <div class="flex-1">
                            <p class="font-semibold text-amber-200">
                                Your folder isn't loaded yet
                            </p>
                            <p class="mt-1 text-amber-100/90">
                                You pointed the app at
                                <code class="text-amber-200 break-all">{{ sources.archive_env_path || 'your folder' }}</code>,
                                but the containers haven't picked it up yet. Run this in the app folder
                                (it <strong>recreates</strong> the containers — a stop/start or restart is
                                <strong>not</strong> enough), then click Re-check:
                            </p>
                            <div class="mt-2 flex items-center gap-3 flex-wrap">
                                <code
                                    class="select-all inline-block px-2.5 py-1.5 rounded bg-gray-950 border border-amber-700/60 text-emerald-300 font-mono text-xs"
                                >docker compose up -d</code>
                                <button
                                    type="button"
                                    @click="fetchSources"
                                    :disabled="sourcesLoading"
                                    class="bg-amber-700 hover:bg-amber-600 disabled:bg-amber-900 text-white px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                                >
                                    {{ sourcesLoading ? 'Checking…' : 'Re-check ↻' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

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
                        <p>{{ archivePathMessage }}</p>
                        <div v-if="archivePathCommand" class="mt-2 flex items-center gap-3 flex-wrap">
                            <code
                                class="select-all inline-block px-2.5 py-1.5 rounded bg-gray-950 border border-emerald-700/60 text-emerald-300 font-mono text-xs"
                            >{{ archivePathCommand }}</code>
                            <button
                                type="button"
                                @click="fetchSources"
                                :disabled="sourcesLoading"
                                class="bg-gray-700 hover:bg-gray-600 disabled:bg-gray-800 text-white px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                            >
                                {{ sourcesLoading ? 'Checking…' : 'Re-check ↻' }}
                            </button>
                        </div>
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
                                Fetch the open datasets straight from their official repos, then ingest.
                                Country scope is optional — leave it empty (in Run Options below) to
                                download <strong>all countries</strong>.
                            </div>

                            <div v-if="source === 'download'" class="mt-3 space-y-3">
                                <!-- geoBoundaries -->
                                <div>
                                    <label class="flex items-start gap-2 text-gray-200 text-xs">
                                        <input type="checkbox" v-model="downloadGeoboundaries"
                                               :disabled="runOptionsDisabled" class="mt-0.5" />
                                        <span>
                                            Jurisdiction boundaries — <strong>geoBoundaries</strong>
                                            <span class="block text-gray-500">github.com/wmgeolab/geoBoundaries (CC BY 4.0)</span>
                                        </span>
                                    </label>
                                    <div v-if="downloadGeoboundaries" class="mt-2 ml-6">
                                        <label class="block">
                                            <span class="text-gray-400 text-[11px]">Release product</span>
                                            <select
                                                v-model="gbRelease"
                                                :disabled="runOptionsDisabled"
                                                class="mt-1 w-full max-w-xs bg-gray-950 border border-gray-700 rounded px-2 py-1 text-xs text-gray-100 focus:border-blue-500 focus:outline-none"
                                            >
                                                <option value="gbOpen">gbOpen — open license, recommended</option>
                                                <option value="gbHumanitarian">gbHumanitarian — humanitarian use</option>
                                                <option value="gbAuthoritative">gbAuthoritative — official government</option>
                                            </select>
                                        </label>
                                    </div>
                                </div>

                                <!-- WorldPop -->
                                <div>
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
                                    <div v-if="downloadWorldpop" class="mt-2 ml-6 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <label class="block">
                                            <span class="text-gray-400 text-[11px]">Year</span>
                                            <select
                                                v-model="wpYear"
                                                :disabled="runOptionsDisabled"
                                                class="mt-1 w-full bg-gray-950 border border-gray-700 rounded px-2 py-1 text-xs text-gray-100 focus:border-blue-500 focus:outline-none"
                                            >
                                                <option value="2020">2020</option>
                                                <option value="2023">2023</option>
                                                <option value="latest">Latest available</option>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-gray-400 text-[11px]">Resolution</span>
                                            <select
                                                v-model="wpResolution"
                                                :disabled="runOptionsDisabled"
                                                class="mt-1 w-full bg-gray-950 border border-gray-700 rounded px-2 py-1 text-xs text-gray-100 focus:border-blue-500 focus:outline-none"
                                            >
                                                <option value="100m">100m — fine (larger)</option>
                                                <option value="1km">1km — coarse (smaller)</option>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-gray-400 text-[11px]">Variant</span>
                                            <select
                                                v-model="wpVariant"
                                                :disabled="runOptionsDisabled"
                                                class="mt-1 w-full bg-gray-950 border border-gray-700 rounded px-2 py-1 text-xs text-gray-100 focus:border-blue-500 focus:outline-none"
                                            >
                                                <option value="constrained">Constrained — built-area masked</option>
                                                <option value="unconstrained">Unconstrained — full extent</option>
                                            </select>
                                        </label>
                                        <label class="flex items-center gap-2 text-gray-300 text-xs self-end pb-1">
                                            <input type="checkbox" v-model="wpUnAdjusted"
                                                   :disabled="runOptionsDisabled" />
                                            <span>Un-adjusted (not UN-matched totals)</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Protomaps -->
                                <div>
                                    <label class="flex items-start gap-2 text-gray-200 text-xs">
                                        <input type="checkbox" v-model="downloadProtomaps"
                                               :disabled="runOptionsDisabled" class="mt-0.5" />
                                        <span>
                                            Basemap tiles — <strong>Protomaps</strong>
                                            <span class="block text-gray-500">
                                                maps.protomaps.com · vector planet basemap for the map background
                                            </span>
                                        </span>
                                    </label>
                                    <div v-if="downloadProtomaps"
                                         class="mt-2 ml-6 rounded border border-amber-800/70 bg-amber-900/20 px-2.5 py-1.5 text-amber-200 text-[11px]">
                                        Heads up: the Protomaps planet build is <strong>~100&nbsp;GB</strong> and takes a long
                                        time to download. Only fetch it if you want the full-world basemap.
                                    </div>
                                </div>

                                <p
                                    v-if="parsedCountries.length === 0"
                                    class="text-amber-300 text-[11px] rounded border border-amber-800/70 bg-amber-900/20 px-2.5 py-1.5"
                                >
                                    <strong>No country scope set</strong> — this downloads <strong>ALL countries</strong>
                                    (the whole world). Expect <strong>14&nbsp;GB+</strong> of boundary + population data
                                    and <strong>hours</strong> of download time. To limit it, enter an ISO3 list in Run
                                    Options below (e.g. NZL,USA).
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
                            Countries (ISO3, comma-separated; empty = all<template v-if="source === 'download'">, a full-world download</template>):
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
                    relationships, and any flagged discrepancies — then accept the
                    map data there (planet scope). Click Continue below once
                    accepted — that triggers apportionment.
                </p>

                <!-- Live open-flag state from the Data Review & Repair plane.
                     Continue is gated server-side on map acceptance, and the
                     acceptance in turn asks for acknowledgment while these
                     stay open — so the count is worth watching from here. -->
                <div v-if="flagState.loaded" class="mb-3">
                    <div v-if="flagState.open > 0"
                         class="rounded-md border border-amber-800/70 bg-amber-900/20 px-3 py-2 text-xs text-amber-200 flex items-center gap-2 flex-wrap">
                        <span>
                            ⚑ {{ flagState.open }} open data flag{{ flagState.open === 1 ? '' : 's' }}
                            — review &amp; repair in the Jurisdiction Viewer →
                        </span>
                        <span v-if="flagState.critical > 0"
                              class="px-1.5 py-0 rounded text-[10px] bg-red-900 text-red-200 border border-red-700">
                            {{ flagState.critical }} critical
                        </span>
                        <span v-if="flagState.warning > 0"
                              class="px-1.5 py-0 rounded text-[10px] bg-amber-900 text-amber-200 border border-amber-700">
                            {{ flagState.warning }} warning
                        </span>
                        <span v-if="flagState.info > 0"
                              class="px-1.5 py-0 rounded text-[10px] bg-gray-700 text-gray-300 border border-gray-600">
                            {{ flagState.info }} info
                        </span>
                    </div>
                    <div v-else class="text-xs text-emerald-300">
                        ✓ No open data flags.
                    </div>
                </div>

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
                    <!-- Map-acceptance gate 422: guidance, not failure — the
                         operator just hasn't accepted the map data yet. -->
                    <div v-if="acceptanceRequired"
                         class="rounded-md border border-amber-800/70 bg-amber-900/20 px-3 py-2 text-xs text-amber-200 max-w-sm text-right">
                        <p>{{ advanceError }}</p>
                        <a href="/jurisdictions"
                           class="inline-block mt-1.5 px-2 py-1 rounded border bg-blue-900/40 border-blue-700 text-blue-200 hover:bg-blue-900/70">
                            Open Jurisdiction Viewer →
                        </a>
                    </div>
                    <span v-else-if="advanceError" class="text-xs text-red-400 max-w-sm text-right">
                        {{ advanceError }}
                    </span>
                </div>
            </div>
    </div>
</template>
