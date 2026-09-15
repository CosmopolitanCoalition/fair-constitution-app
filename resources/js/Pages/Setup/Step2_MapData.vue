<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import ReviewIssuesSection from '@/Components/Setup/ReviewIssuesSection.vue'
import GeodataPullPanel from '@/Components/Geodata/GeodataPullPanel.vue'
import StackedProgressBars from '@/Components/Setup/StackedProgressBars.vue'
import ScanDetectorBars from '@/Components/Geodata/ScanDetectorBars.vue'
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

const { t } = useI18n()

const props = defineProps({
    step: { type: Number, required: true },
    settings: { type: Object, required: true },
    is_dev_world: { type: Boolean, default: false },
    scale_mode: { type: String, default: 'eager' },
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
// THE HANDOFF (WoS 2026-09-02): the download finished and the pull run has
// not started. { chained_at, waiting_seconds } from the backend; null otherwise.
const handoff             = ref(null)
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

// Ingestion engine (GEODATA_PULL_ENGINE_PLAN.md): 'pull' = the multithreaded
// pull engine (worker pool + per-worker visibility + incremental requeue) —
// the default for archive/folder sources. 'legacy' = the original
// single-threaded seed_database.py path. A download run always uses legacy
// (the downloader step is a legacy-supervisor concern).
const engine    = ref('pull')               // pull | legacy
const pullPanel = ref(null)
const enginePull = computed(() => engine.value === 'pull' && source.value !== 'download')

// Live pull-run state, reported up by GeodataPullPanel. While a pull run is
// ACTIVE the legacy Live Progress panel is hidden: N concurrent workers make
// bars.json interleaved garbage (and pull workers now suppress those writes
// entirely) — two dueling progress surfaces read as chaos.
const pullRun = ref(null)
// Acceptance-scan detectors, reported up by the pull panel. Rendered in
// Review & Accept rather than the ingestion panel — the scan measures map
// health and its findings are the flag list directly below it.
const scanState = ref(null)
const pullRunActive = computed(() => pullRun.value && ['running', 'halted'].includes(pullRun.value.status))

// ─── Rewind control (operator, 2026-08-05) — fused into the start button.
// "Fresh run" is the only choice until phases complete; each completed phase
// unlocks its rewind point. Rewinding resets that point + everything
// downstream and moves forward from there — no fresh ingestion to test a fix.
const rewindTarget = ref('fresh')
const REWIND_OPTS = [
    { v: 'fresh',              t: t('c_setup.step2_map_data.rewind_fresh', 'Fresh run'),                    need: null },
    { v: 'boundaries_rasters', t: t('c_setup.step2_map_data.rewind_boundaries_rasters', 'Re-run boundaries + rasters'),  need: ['boundaries', 'rasters'] },
    { v: 'boundaries',         t: t('c_setup.step2_map_data.rewind_boundaries', 'Re-run boundaries'),            need: ['boundaries'] },
    { v: 'rasters',            t: t('c_setup.step2_map_data.rewind_rasters', 'Re-run rasters'),              need: ['rasters'] },
    { v: 'resolve_attribute',  t: t('c_setup.step2_map_data.rewind_resolve_attribute', 'Re-resolve + attribute'),       need: ['resolving', 'attribution'] },
    { v: 'resolve',            t: t('c_setup.step2_map_data.rewind_resolve', 'Re-resolve'),                   need: ['resolving'] },
    { v: 'attribute',          t: t('c_setup.step2_map_data.rewind_attribute', 'Re-attribute'),                 need: ['attribution'] },
    { v: 'scan',               t: t('c_setup.step2_map_data.rewind_scan', 'Re-scan'),                      need: ['scanning'] },
]
// THE ESCAPE-HATCH LAW, frontend edition (operator, 2026-08-05 — the FIFTH
// catch of the same law: the backend learned to seize any state while this
// computed kept the options greyed behind "run active" and behind phase
// timestamps that rewinding itself deletes). A recovery control is never
// blocked by the state it exists to recover from: every rewind option is
// ENABLED whenever a run exists, in any state. The backend seizes; the
// operator decides.
const rewindOptions = computed(() => REWIND_OPTS.map(o => ({
    ...o,
    enabled: o.v === 'fresh' || !!pullRun.value,
})))
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
const wpYear        = ref('2023')          // '2020' | '2023' | 'latest' — 2023 = the equivalence pin's frozen R2025A vintage
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
            sourcesError.value = t('c_setup.step2_map_data.err_read_sources', { status: res.status })
            return
        }
        sources.value = await res.json()
    } catch (e) {
        sourcesError.value = t('c_setup.step2_map_data.err_read_sources_generic', { detail: String(e) })
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
        handoff.value        = data.handoff || null
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
        archivePathError.value = t('c_setup.step2_map_data.err_folder_required', 'Enter at least one folder path.')
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
            archivePathError.value = data.error || t('c_setup.step2_map_data.err_save_folder', { status: res.status })
            return
        }
        archivePathMessage.value = data.message
            || t('c_setup.step2_map_data.folder_saved', 'Saved. Re-run docker compose up -d, then reload this page.')
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
            submitError.value = t('c_setup.step2_map_data.err_choose_dataset', 'Choose at least one dataset to download (boundaries, population, and/or basemap tiles).')
            return
        }
        // Country scope is now OPTIONAL — an empty list downloads ALL countries
        // (a full-world pull). No client gate here; the UI warns about the size.
    }
    if (source.value === 'folder' && customDataRoot.value.trim() === '') {
        submitError.value = t('c_setup.step2_map_data.err_container_path', 'Enter the container path to ingest (e.g. /archive/snapshots/2026-05).')
        return
    }

    submitting.value = true
    try {
        // Pull engine: a much smaller contract — Laravel creates the run +
        // manifest item and the ETL supervisor maintains the worker pool; the
        // GeodataPullPanel below is the live dashboard. Legacy-only options
        // (fresh / skip-population / pause-on-exception) don't apply.
        if (enginePull.value) {
            // Rewind path: a completed phase re-runs IN PLACE (items reset +
            // downstream, pointer rewound) instead of a fresh ingestion.
            if (rewindTarget.value !== 'fresh') {
                const rw = await csrfFetch('/api/setup/wizard/step2/pull-control', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'rewind', target: rewindTarget.value }),
                })
                const rwData = await rw.json().catch(() => ({}))
                if (!rw.ok) {
                    submitError.value = rwData.error || t('c_setup.step2_map_data.err_rewind', { status: rw.status })
                    return
                }
                rewindTarget.value = 'fresh'
                pullPanel.value?.fetchProgress?.()
                return
            }
            const res = await csrfFetch('/api/setup/wizard/step2/pull-start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    source:    source.value,
                    data_root: source.value === 'folder' ? customDataRoot.value.trim() : null,
                    countries: parsedCountries.value,
                    // The dropdown's Fresh run means FRESH: purge the geodata
                    // domain first so the planet rebuilds from source
                    // (operator-caught 2026-08-05 — it was a warm re-pass).
                    fresh:     true,
                }),
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                submitError.value = data.error || t('c_setup.step2_map_data.err_run_submit', { status: res.status })
                return
            }
            await pullPanel.value?.fetchProgress()
            return
        }

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
            submitError.value = data.error || t('c_setup.step2_map_data.err_run_submit', { status: res.status })
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
            submitError.value = data.error || t('c_setup.step2_map_data.err_control', { action, status: res.status })
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

// Inline map acceptance (operator request 2026-07-19): accept directly from
// Step 2 — the Jurisdiction Viewer detour is for reviewing/repairing flags,
// not a mandatory hallway. Same endpoint + acknowledgment contract as the
// viewer's button; open flags surface in a confirm() before acceptance.
const accepting = ref(false)

// THE THREE ACTIVATION MODES (operator, 2026-08-08 — replaces the binary
// switch). Chosen beside Continue, stored at acceptance:
//   eager      — Activate & Scale Institutions Now (full-scale build; its
//                completion chains institution provisioning)
//   population — Activate & Scale As Players Join (CLK-06 boots each place
//                at its resident threshold)
//   manual     — Activate & Scale Manually (Activate controls + governance
//                forms; nothing automatic)
// Dev sandbox worlds add "simulate at scale" under eager: the sim populates
// the built world through the real governance engine.
const MODE_OPTS = [
    { v: 'eager',      t: t('c_setup.step2_map_data.mode_eager', 'Activate & Scale Institutions Now') },
    { v: 'population', t: t('c_setup.step2_map_data.mode_population', 'Activate & Scale Institutions As Players Join') },
    { v: 'manual',     t: t('c_setup.step2_map_data.mode_manual', 'Activate & Scale Institutions Manually') },
]
const scaleMode       = ref(props.scale_mode || 'eager')
const simulateAtScale = ref(false)
const rehooking = ref(false)
const rehookMsg = ref('')

async function startPlanetGeneration() {
    if (rehooking.value) return
    rehooking.value = true
    rehookMsg.value = ''
    try {
        const res = await csrfFetch('/api/jurisdictions/accept-maps', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ start_autoscale: true }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            rehookMsg.value = data.error || t('c_setup.step2_map_data.err_planet_start', { status: res.status })
            return
        }
        rehookMsg.value = data.autoscale_run_id
            ? t('c_setup.step2_map_data.planet_running', { id: String(data.autoscale_run_id).slice(0, 8) })
            : t('c_setup.step2_map_data.planet_started', 'Planet-wide generation started.')
    } catch (e) {
        rehookMsg.value = String(e?.message || e)
    } finally {
        rehooking.value = false
    }
}

async function acceptHere() {
    if (accepting.value) return
    accepting.value = true
    advanceError.value = ''
    try {
        const acceptBody = {
            scale_mode: scaleMode.value,
            simulate_at_scale: scaleMode.value === 'eager' && simulateAtScale.value,
        }
        let res = await csrfFetch('/api/jurisdictions/accept-maps', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(acceptBody),
        })
        let data = await res.json().catch(() => ({}))

        if (res.status === 422 && data.requires_acknowledgment) {
            const f = data.open_flags || {}
            const ok = confirm(
                t('c_setup.step2_map_data.accept_confirm', {
                    critical: f.critical ?? 0, warning: f.warning ?? 0, info: f.info ?? 0,
                })
            )
            if (!ok) return
            res = await csrfFetch('/api/jurisdictions/accept-maps', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...acceptBody, acknowledge_open_flags: true }),
            })
            data = await res.json().catch(() => ({}))
        }

        if (!res.ok || !data.ok) {
            advanceError.value = data.error || t('c_setup.step2_map_data.err_accept', { status: res.status })
            return
        }

        acceptanceRequired.value = false
        // Landing by mode (operator, 2026-08-08): eager keeps the Step-3
        // build dashboard (watch the full-scale build); population/manual
        // continue straight to the Jurisdictions List, where the Activate
        // controls and explanations live.
        if (scaleMode.value === 'eager') {
            await advance()
        } else {
            router.visit('/jurisdictions')
        }
    } catch (e) {
        advanceError.value = String(e?.message || e)
    } finally {
        accepting.value = false
    }
}

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
            advanceError.value = data.error || data.message || t('c_setup.step2_map_data.err_continue', { status: res.status })
        }
    } catch (e) {
        advanceError.value = e.message || t('c_setup.step2_map_data.err_continue_network', 'Network error while continuing to districting.')
    } finally {
        advancing.value = false
        apportioning.value = false
    }
}

// ─── Derived UI state ───────────────────────────────────────────────────────

const canAdvance = computed(() => counts.value.adm0 > 0 && counts.value.adm1 > 0)

// ONE BUTTON IN THE CONTINUE SPOT (operator, 2026-08-04). "Accept Map Data &
// Continue" and "Continue" were two buttons in two places that did the same
// thing — acceptHere() already calls advance() once acceptance lands, so the
// only real difference was whether acceptance had happened yet. That is a
// LABEL, not a second control.
const mapAccepted = computed(() => !! props.settings?.map_accepted_at)

const continueLabel = computed(() => {
    if (apportioning.value) return t('c_setup.step2_map_data.continue_sizing', 'Sizing legislatures…')
    if (accepting.value)    return t('c_setup.step2_map_data.continue_accepting', 'Accepting…')
    if (advancing.value)    return t('c_setup.step2_map_data.continue_saving', 'Saving…')

    // ONE plain Continue (operator, 2026-08-08): the mode dropdown beside it
    // carries what acceptance starts; the button just continues.
    return t('c_setup.step2_map_data.continue', 'Continue →')
})

function continueFromStep2() {
    return mapAccepted.value ? advance() : acceptHere()
}
const isRunning  = computed(() => lifecycle.value === 'running')
// THE DOWNLOAD CONTROLS (WoS 2026-09-02): the backend accepted halt / pause /
// resume since the first commit and the page never offered them (the
// escape-hatch law: a recovery control exists on every layer). The pause
// state comes from the supervisor's `_paused_at` stamp in bars.json.
const downloadPaused = computed(() => !!(bars.value && bars.value._paused_at))
// The scheduler consumes the handoff marker within a minute. Past two minutes
// the scheduler container is not running, and the card says so.
const handoffLate      = computed(() => (handoff.value?.waiting_seconds ?? 0) > 120)
const handoffWaitLabel = computed(() => {
    const s = handoff.value?.waiting_seconds ?? 0
    return s < 90 ? t('c_setup.step2_map_data.wait_seconds', { s }) : t('c_setup.step2_map_data.wait_minutes', { m: Math.round(s / 60) })
})
const runOptionsDisabled = computed(() => isRunning.value || submitting.value)

// Label for the primary "Start" button — download runs read differently.
const startButtonLabel = computed(() => {
    if (submitting.value) return t('c_setup.step2_map_data.btn_submitting', 'Submitting…')
    // ESCAPE-HATCH LAW (frontend enablement layer). The pull-engine Fresh /
    // Rewind control SEIZES from any state, so its label must state the action
    // it will take — never defer to "Run in progress…" because a stale or
    // halted run still reports lifecycle='running'. That deferral is exactly
    // what left Fresh looking inert on a halted run.
    if (enginePull.value) {
        return rewindTarget.value === 'fresh'
            ? t('c_setup.step2_map_data.btn_start_ingestion', 'Start Multithreaded Ingestion') : t('c_setup.step2_map_data.btn_rewind_rerun', 'Rewind & Re-run')
    }
    if (isRunning.value)  return t('c_setup.step2_map_data.btn_run_in_progress', 'Run in progress…')
    if (source.value === 'download') return t('c_setup.step2_map_data.btn_download_ingest', 'Download + Ingest')
    return t('c_setup.step2_map_data.btn_start_etl', 'Start ETL Run')
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
            <SetupStepper :current="2" :completed="settings.setup_step_completed" :steps="settings.ladder" />

            <header class="mt-8 mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    {{ t('c_setup.step2_map_data.heading', 'Load Boundaries + Population Data') }}
                </h1>
                <p class="text-gray-400 text-sm">
                    {{ t('c_setup.step2_map_data.intro', 'Point at your map data, then run the ETL pipeline. Progress streams live from the ETL container. Once the run completes, review any flagged discrepancies before continuing to districting.') }}
                </p>
            </header>

            <!-- Your map data (detected inventory at the REAL container path) -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
                <div class="flex items-baseline justify-between mb-1">
                    <h2 class="text-white font-semibold">{{ t('c_setup.step2_map_data.section1_heading', '1. Your Map Data & Source') }}</h2>
                    <button
                        type="button"
                        @click="fetchSources"
                        :disabled="sourcesLoading"
                        class="text-xs text-gray-400 hover:text-gray-200 disabled:opacity-50"
                    >
                        {{ sourcesLoading ? t('c_setup.step2_map_data.checking', 'Checking…') : t('c_setup.step2_map_data.recheck', 'Re-check ↻') }}
                    </button>
                </div>
                <p class="text-gray-500 text-xs mb-4" v-html="t('c_setup.step2_map_data.etl_reads_from', { mount: sources?.archive_mount || '/archive' })"></p>

                <!-- Half-applied archive: .env points at a real folder but the
                     containers haven't been recreated, so /archive is still
                     empty. This is the #1 "the archive won't take" trap — the
                     operator ran stop/start (or Docker Desktop restart), which
                     reuses the old mount. Recreation via `up -d` is required. -->
                <div
                    v-if="sources?.archive_empty"
                    class="mb-4 rounded-md border border-sky-700 bg-sky-900/20 px-4 py-3 text-sky-100 text-sm"
                >
                    <p class="font-semibold text-sky-200">{{ t('c_setup.step2_map_data.empty_title', 'Your folder is mounted and empty') }}</p>
                    <p class="mt-1 text-sky-100/90" v-html="t('c_setup.step2_map_data.empty_body', { path: sources.archive_env_path })"></p>
                </div>
                <div
                    v-else-if="sources?.apply_pending"
                    class="mb-4 rounded-md border border-amber-600 bg-amber-900/30 px-4 py-3 text-amber-100 text-sm"
                >
                    <div class="flex items-start gap-2">
                        <span class="text-amber-300 text-base leading-none mt-0.5">⚠</span>
                        <div class="flex-1">
                            <p class="font-semibold text-amber-200">
                                {{ t('c_setup.step2_map_data.apply_pending_title', 'Your folder isn\'t loaded yet') }}
                            </p>
                            <p class="mt-1 text-amber-100/90" v-html="t('c_setup.step2_map_data.apply_pending_body', { path: sources.archive_env_path || t('c_setup.step2_map_data.your_folder', 'your folder') })"></p>
                            <div class="mt-2 flex items-center gap-3 flex-wrap">
                                <code
                                    class="select-all inline-block px-2.5 py-1.5 rounded bg-gray-950 border border-amber-700/60 text-emerald-300 font-mono text-xs"
                                    data-no-i18n
                                >docker compose up -d</code>
                                <button
                                    type="button"
                                    @click="fetchSources"
                                    :disabled="sourcesLoading"
                                    class="bg-amber-700 hover:bg-amber-600 disabled:bg-amber-900 text-white px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                                >
                                    {{ sourcesLoading ? t('c_setup.step2_map_data.checking', 'Checking…') : t('c_setup.step2_map_data.recheck', 'Re-check ↻') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="sourcesError" class="mb-3 text-sm text-red-400">{{ sourcesError }}</div>

                <div v-if="sourcesLoading && !sources" class="text-gray-500 text-sm italic">
                    {{ t('c_setup.step2_map_data.reading_mount', 'Reading the archive mount…') }}
                </div>

                <template v-else-if="sources">
                    <div
                        v-if="!sources.archive_present"
                        class="mb-4 rounded-md border border-amber-800/70 bg-amber-900/20 px-3 py-2 text-amber-200 text-xs"
                        v-html="t('c_setup.step2_map_data.mount_not_present', { mount: sources.archive_mount })"
                    ></div>

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
                                    {{ t('c_setup.step2_map_data.countries_detected', { n: sources.datasets.geoboundaries.countries }) }}
                                </template>
                                <template v-else>{{ t('c_setup.step2_map_data.not_detected', 'Not detected') }}</template>
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
                                    {{ t('c_setup.step2_map_data.countries_detected', { n: sources.datasets.worldpop.countries }) }}
                                </template>
                                <template v-else>{{ t('c_setup.step2_map_data.not_detected', 'Not detected') }}</template>
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
                                    {{ t('c_setup.step2_map_data.basemap_files', { n: sources.datasets.protomaps.files.length }) }}
                                </template>
                                <template v-else>{{ t('c_setup.step2_map_data.not_detected_optional', 'Not detected (optional)') }}</template>
                            </div>
                            <div class="text-gray-600 text-[11px] font-mono mt-1 break-all">
                                {{ sources.datasets.protomaps.path }}
                            </div>
                        </div>
                    </div>
                </template>

                <!-- LOCAL FOLDER override -->
                <div class="mt-5 pt-5 border-t border-gray-800">
                    <h3 class="text-white text-sm font-semibold mb-1">{{ t('c_setup.step2_map_data.local_folder_heading', 'Point at a local folder') }}</h3>
                    <p class="text-gray-500 text-xs mb-3" v-html="t('c_setup.step2_map_data.local_folder_help', 'If your map files live somewhere else on this computer, enter that folder here. It\'s written to <code class=&quot;text-sky-300&quot;>ARCHIVE_PATH</code> in your <code>.env</code> so the container remounts <code>/archive</code> from it. On Windows, use your host path (e.g. <code class=&quot;text-emerald-300&quot;>D:\\fair-constitution-map-files</code>).')"></p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-gray-300 text-xs">{{ t('c_setup.step2_map_data.archive_path_label', 'Map data folder (ARCHIVE_PATH)') }}</span>
                            <input
                                type="text"
                                v-model="archivePathInput"
                                :placeholder="t('c_setup.step2_map_data.archive_path_ph', 'D:\\fair-constitution-map-files')"
                                class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                       text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none"
                            />
                        </label>
                        <label class="block">
                            <span class="text-gray-300 text-xs">{{ t('c_setup.step2_map_data.protomaps_path_label', 'Basemap tiles folder (PROTOMAPS_DIR, optional)') }}</span>
                            <input
                                type="text"
                                v-model="protomapsPathInput"
                                :placeholder="t('c_setup.step2_map_data.protomaps_path_ph', 'D:\\fair-constitution-map-files\\protomaps')"
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
                            {{ savingArchivePath ? t('c_setup.step2_map_data.saving', 'Saving…') : t('c_setup.step2_map_data.save_folder', 'Save folder path') }}
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
                                {{ sourcesLoading ? t('c_setup.step2_map_data.checking', 'Checking…') : t('c_setup.step2_map_data.recheck', 'Re-check ↻') }}
                            </button>
                        </div>
                    </div>
                </div>
            <!-- Blocks welded (operator, 2026-08-04): the detected
                 inventory and the source chooser are the same subject — what
                 data you have and where it comes from — so they read as one
                 step. Inventory first, then the chooser that acts on it. -->
            <div class="mt-8 pt-6 border-t border-gray-800">
                <h3 class="text-white font-semibold mb-4">{{ t('c_setup.step2_map_data.data_source_heading', 'Data source') }}</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Local Archive (default) — bind-mounted /archive folder -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-md border cursor-pointer transition-colors"
                        :class="source === 'archive' ? 'border-blue-500 bg-blue-900/20' : 'border-gray-800 hover:border-gray-700'"
                    >
                        <input type="radio" value="archive" v-model="source"
                               class="mt-1" :disabled="runOptionsDisabled" />
                        <div class="flex-1">
                            <div class="text-white font-semibold text-sm">{{ t('c_setup.step2_map_data.source_archive_title', 'Local Archive (default)') }}</div>
                            <div class="text-gray-400 text-xs mt-1" v-html="t('c_setup.step2_map_data.source_archive_desc', { mount: sources?.archive_mount || '/archive' })"></div>
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
                            <div class="text-white font-semibold text-sm">{{ t('c_setup.step2_map_data.source_folder_title', 'Custom Folder') }}</div>
                            <div class="text-gray-400 text-xs mt-1" v-html="t('c_setup.step2_map_data.source_folder_desc', 'Ingest a specific <em>container</em> path — an alternate snapshot or a sub-directory of <code>/archive</code>. (To point at a different folder on your computer, use &quot;Point at a local folder&quot; above instead.)')"></div>
                            <input v-if="source === 'folder'"
                                   type="text"
                                   v-model="customDataRoot"
                                   :placeholder="t('c_setup.step2_map_data.custom_root_ph', '/archive/snapshots/2026-05')"
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
                            <div class="text-white font-semibold text-sm">{{ t('c_setup.step2_map_data.source_download_title', 'Download from official sources') }}</div>
                            <div class="text-gray-400 text-xs mt-1" v-html="t('c_setup.step2_map_data.source_download_desc', 'Fetch the open datasets straight from their official repos, then ingest. Fetches <strong>all countries</strong> — the full-world archive the multithreaded engine ingests.')"></div>

                            <div v-if="source === 'download'" class="mt-3 space-y-3">
                                <!-- geoBoundaries -->
                                <div>
                                    <label class="flex items-start gap-2 text-gray-200 text-xs">
                                        <input type="checkbox" v-model="downloadGeoboundaries"
                                               :disabled="runOptionsDisabled" class="mt-0.5" />
                                        <span v-html="t('c_setup.step2_map_data.dl_geoboundaries', 'Jurisdiction boundaries — <strong>geoBoundaries</strong> <span class=&quot;block text-gray-500&quot;>github.com/wmgeolab/geoBoundaries (CC BY 4.0)</span>')"></span>
                                    </label>
                                    <div v-if="downloadGeoboundaries" class="mt-2 ml-6">
                                        <label class="block">
                                            <span class="text-gray-400 text-[11px]">{{ t('c_setup.step2_map_data.release_product', 'Release product') }}</span>
                                            <select
                                                v-model="gbRelease"
                                                :disabled="runOptionsDisabled"
                                                class="mt-1 w-full max-w-xs bg-gray-950 border border-gray-700 rounded px-2 py-1 text-xs text-gray-100 focus:border-blue-500 focus:outline-none"
                                            >
                                                <option value="gbOpen">{{ t('c_setup.step2_map_data.gb_open', 'gbOpen — open license, recommended') }}</option>
                                                <option value="gbHumanitarian">{{ t('c_setup.step2_map_data.gb_humanitarian', 'gbHumanitarian — humanitarian use') }}</option>
                                                <option value="gbAuthoritative">{{ t('c_setup.step2_map_data.gb_authoritative', 'gbAuthoritative — official government') }}</option>
                                            </select>
                                        </label>
                                    </div>
                                </div>

                                <!-- WorldPop -->
                                <div>
                                    <label class="flex items-start gap-2 text-gray-200 text-xs">
                                        <input type="checkbox" v-model="downloadWorldpop"
                                               :disabled="runOptionsDisabled" class="mt-0.5" />
                                        <span v-html="t('c_setup.step2_map_data.dl_worldpop', 'Population — <strong>WorldPop</strong> <span class=&quot;block text-gray-500&quot;>data.worldpop.org (CC BY 4.0) · pulls boundaries too (needed to attribute population)</span>')"></span>
                                    </label>
                                    <div v-if="downloadWorldpop" class="mt-2 ml-6 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <label class="block">
                                            <span class="text-gray-400 text-[11px]">{{ t('c_setup.step2_map_data.wp_year', 'Year') }}</span>
                                            <select
                                                v-model="wpYear"
                                                :disabled="runOptionsDisabled"
                                                class="mt-1 w-full bg-gray-950 border border-gray-700 rounded px-2 py-1 text-xs text-gray-100 focus:border-blue-500 focus:outline-none"
                                            >
                                                <option value="2020">2020</option>
                                                <option value="2023">2023</option>
                                                <option value="latest">{{ t('c_setup.step2_map_data.wp_latest', 'Latest available') }}</option>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-gray-400 text-[11px]">{{ t('c_setup.step2_map_data.wp_resolution', 'Resolution') }}</span>
                                            <select
                                                v-model="wpResolution"
                                                :disabled="runOptionsDisabled"
                                                class="mt-1 w-full bg-gray-950 border border-gray-700 rounded px-2 py-1 text-xs text-gray-100 focus:border-blue-500 focus:outline-none"
                                            >
                                                <option value="100m">{{ t('c_setup.step2_map_data.wp_res_100m', '100m — fine (larger)') }}</option>
                                                <option value="1km">{{ t('c_setup.step2_map_data.wp_res_1km', '1km — coarse (smaller)') }}</option>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-gray-400 text-[11px]">{{ t('c_setup.step2_map_data.wp_variant', 'Variant') }}</span>
                                            <select
                                                v-model="wpVariant"
                                                :disabled="runOptionsDisabled"
                                                class="mt-1 w-full bg-gray-950 border border-gray-700 rounded px-2 py-1 text-xs text-gray-100 focus:border-blue-500 focus:outline-none"
                                            >
                                                <option value="constrained">{{ t('c_setup.step2_map_data.wp_var_constrained', 'Constrained — built-area masked') }}</option>
                                                <option value="unconstrained">{{ t('c_setup.step2_map_data.wp_var_unconstrained', 'Unconstrained — full extent') }}</option>
                                            </select>
                                        </label>
                                        <label class="flex items-center gap-2 text-gray-300 text-xs self-end pb-1">
                                            <input type="checkbox" v-model="wpUnAdjusted"
                                                   :disabled="runOptionsDisabled" />
                                            <span>{{ t('c_setup.step2_map_data.wp_unadjusted', 'Un-adjusted (not UN-matched totals)') }}</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Protomaps -->
                                <div>
                                    <label class="flex items-start gap-2 text-gray-200 text-xs">
                                        <input type="checkbox" v-model="downloadProtomaps"
                                               :disabled="runOptionsDisabled" class="mt-0.5" />
                                        <span v-html="t('c_setup.step2_map_data.dl_protomaps', 'Basemap tiles — <strong>Protomaps</strong> <span class=&quot;block text-gray-500&quot;>maps.protomaps.com · vector planet basemap for the map background</span>')"></span>
                                    </label>
                                    <div v-if="downloadProtomaps"
                                         class="mt-2 ml-6 rounded border border-amber-800/70 bg-amber-900/20 px-2.5 py-1.5 text-amber-200 text-[11px]"
                                         v-html="t('c_setup.step2_map_data.protomaps_warning', 'Heads up: the Protomaps planet build is <strong>~100&nbsp;GB</strong> and takes a long time to download. Only fetch it if you want the full-world basemap.')"></div>
                                </div>

                                <p
                                    v-if="parsedCountries.length === 0"
                                    class="text-amber-300 text-[11px] rounded border border-amber-800/70 bg-amber-900/20 px-2.5 py-1.5"
                                    v-html="t('c_setup.step2_map_data.no_scope_warning', '<strong>No country scope set</strong> — this downloads <strong>ALL countries</strong> (the whole world). Expect <strong>14&nbsp;GB+</strong> of boundary + population data and <strong>hours</strong> of download time. To limit it, enter an ISO3 list in Run Options below (e.g. NZL,USA).')"
                                ></p>
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
                            <div class="text-white font-semibold text-sm">{{ t('c_setup.step2_map_data.source_upload_title', 'Browser Upload (planned)') }}</div>
                            <div class="text-gray-400 text-xs mt-1">
                                {{ t('c_setup.step2_map_data.source_upload_desc', 'Upload a tarball or folder from this browser. Multipart upload handler not yet wired.') }}
                            </div>
                        </div>
                    </label>
                </div>
            </div>

                <!-- START lives at the END of block 1 (operator, 2026-08-04):
                     you inspect what was detected, choose where it comes from,
                     then start. It sat in the Ingestion block before, which
                     asked you to scroll past the thing you were configuring to
                     find the button that acts on it. -->
                <div class="mt-6 pt-5 border-t border-gray-800 flex items-center justify-between gap-3 flex-wrap">
                    <p class="text-gray-500 text-xs flex-1 min-w-[16rem]">
                        {{ t('c_setup.step2_map_data.pull_engine_blurb', 'Multithreaded pull engine — a pool of workers ingests countries in parallel with live per-worker view, halt/resume, and incremental commits. Failures flag for review; they never sink the run.') }}
                    </p>
                    <div class="flex items-center gap-3 shrink-0">
                        <span v-if="submitError" class="text-red-400 text-sm">{{ submitError }}</span>
                        <select
                            v-if="enginePull"
                            v-model="rewindTarget"
                            :disabled="submitting"
                            class="bg-gray-800 border border-gray-700 text-gray-200 text-sm rounded-md px-3 py-2"
                            :aria-label="t('c_setup.step2_map_data.run_mode_aria', 'Run mode — fresh run or rewind to a completed phase')"
                        >
                            <option v-for="o in rewindOptions" :key="o.v" :value="o.v" :disabled="!o.enabled">
                                {{ o.t }}
                            </option>
                        </select>
                        <button
                            type="button"
                            @click="submitRun"
                            :disabled="enginePull ? submitting : runOptionsDisabled"
                            class="bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 text-white px-5 py-2 rounded-md font-semibold transition-colors"
                        >
                            {{ startButtonLabel }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- Run Options retired (operator, 2026-08-03): the multithreaded
                 pull engine is the only ingestion path — the legacy
                 single-threaded engine, its purge/skip/pause dials, and the
                 country scope are no longer offered.
                 START moved up into block 1 (operator, 2026-08-04) — it belongs
                 with the source you configure, not with the progress it
                 produces. This block is now purely the live run. -->
            <!-- The pull-engine dashboard IS the ingestion card — its own
                 bordered panel, titled "2. GeoData Ingestion". No redundant
                 outer "2. Ingestion" box around it (operator, 2026-08-05).
                 Renders only when a pull run exists. -->
            <!-- Official-source DOWNLOAD + legacy-ingest live panel
                 (2026-08-29). When LiveProgress was retired for the pull
                 dashboard, the download-fetch flow LOST its renderer: the
                 page polled lifecycle/bars every 2 s and rendered none of it
                 — a running full-world download looked like a dead page
                 (operator: "it looks like nothing happened"). This section
                 renders the already-polled bars for any running run the pull
                 panel does not cover (the download phase and the legacy
                 seed that follows it), so download bars lead straight into
                 ingestion bars. -->
            <!-- THE HANDOFF (WoS 2026-09-02): the download finished and the
                 multithreaded pull run has not started yet. The app scheduler
                 (geodata:chain-download) consumes the handoff marker on its
                 next tick, normally within a minute. A long wait means the
                 scheduler container is not running; the page says so instead
                 of rendering "import finished" over an empty planet. -->
            <section v-if="lifecycle === 'handoff'"
                     class="bg-gray-900 border border-sky-900 rounded-lg p-6 mb-6">
                <div class="flex items-baseline justify-between mb-1">
                    <h2 class="text-white font-semibold">{{ t('c_setup.step2_map_data.handoff_heading', '2. Download complete — waiting for the ingest engine') }}</h2>
                    <span class="text-[11px]" :class="handoffLate ? 'text-amber-400' : 'text-sky-400'">
                        {{ handoffLate ? t('c_setup.step2_map_data.handoff_late', 'late') : t('c_setup.step2_map_data.handoff_handing_off', 'handing off') }}
                    </span>
                </div>
                <p class="text-gray-400 text-xs mb-3">
                    {{ t('c_setup.step2_map_data.handoff_body', { wait: handoffWaitLabel }) }}
                </p>
                <div v-if="handoffLate"
                     class="mb-3 rounded-md border border-amber-800/70 bg-amber-900/20 px-3 py-2 text-xs text-amber-200 leading-relaxed"
                     v-html="t('c_setup.step2_map_data.handoff_late_note', 'The handoff has waited more than two minutes. The scheduler container is not consuming it. On the server run <code class=&quot;text-amber-100&quot;>docker compose ps scheduler</code> and <code class=&quot;text-amber-100&quot;>docker compose logs --tail 50 scheduler</code>. A restart loop there means its memory cap is below its need: update the app, run the installer again, then <code class=&quot;text-amber-100&quot;>docker compose up -d scheduler</code>. The handoff marker stays on disk and the run starts on the first surviving tick.')"></div>
                <StackedProgressBars :bars="bars" :current="current" :lifecycle="lifecycle" />
            </section>

            <section v-if="isRunning && !pullRunActive"
                     class="bg-gray-900 border border-emerald-900 rounded-lg p-6 mb-6">
                <div class="flex items-baseline justify-between mb-1">
                    <h2 class="text-white font-semibold">{{ t('c_setup.step2_map_data.live_heading', '2. Download & Ingestion — live') }}</h2>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px]" :class="downloadPaused ? 'text-amber-400' : 'text-emerald-400'">
                            {{ downloadPaused ? t('c_setup.step2_map_data.status_paused', 'paused') : t('c_setup.step2_map_data.status_running', 'running') }}
                        </span>
                        <button v-if="!downloadPaused" type="button" @click="sendControl('pause')"
                                :disabled="pendingControl.pause"
                                class="px-2.5 py-1 rounded text-[11px] font-semibold border border-amber-700 text-amber-200 hover:bg-amber-900/40 disabled:opacity-50">
                            {{ pendingControl.pause ? t('c_setup.step2_map_data.btn_pausing', 'Pausing…') : t('c_setup.step2_map_data.btn_pause', 'Pause') }}
                        </button>
                        <button v-else type="button" @click="sendControl('resume')"
                                :disabled="pendingControl.resume"
                                class="px-2.5 py-1 rounded text-[11px] font-semibold border border-emerald-700 text-emerald-200 hover:bg-emerald-900/40 disabled:opacity-50">
                            {{ pendingControl.resume ? t('c_setup.step2_map_data.btn_resuming', 'Resuming…') : t('c_setup.step2_map_data.btn_resume', 'Resume') }}
                        </button>
                        <button type="button" @click="sendControl('halt')"
                                :disabled="pendingControl.halt"
                                class="px-2.5 py-1 rounded text-[11px] font-semibold border border-red-700 text-red-200 hover:bg-red-900/40 disabled:opacity-50">
                            {{ pendingControl.halt ? t('c_setup.step2_map_data.btn_halting', 'Halting…') : t('c_setup.step2_map_data.btn_halt', 'Halt') }}
                        </button>
                    </div>
                </div>
                <p class="text-gray-500 text-xs mb-4">
                    {{ t('c_setup.step2_map_data.live_blurb', 'Fetching from the official hosts on parallel lanes, then ingesting. Every bar is written by the engine itself — nothing here is fabricated.') }}
                </p>
                <StackedProgressBars :bars="bars" :current="current" :lifecycle="lifecycle" />
                <div v-if="current?.sub_phase" class="mt-3 text-xs text-gray-400">
                    <span class="text-gray-500">{{ t('c_setup.step2_map_data.now', 'now:') }}</span>
                    {{ current.name || current.iso_code }} — {{ current.sub_phase }}
                </div>
                <div v-if="logBuffer.length" class="mt-3 max-h-40 overflow-y-auto rounded bg-gray-950 border border-gray-800 p-2 font-mono text-[11px] text-gray-400 leading-snug">
                    <div v-for="(ln, i) in logBuffer.slice(-14)" :key="i">{{ ln }}</div>
                </div>
            </section>

            <GeodataPullPanel ref="pullPanel"
                              @run-state="pullRun = $event"
                              @scan-state="scanState = $event" />

            <!-- Legacy Live Progress panel retired (operator, 2026-08-03):
                 the pull dashboard above is the one truth for ingestion.
                 (LiveProgress.vue itself remains for the download-fetch
                 flow's plumbing history; nothing renders it here.) -->

            <!-- 4. Review & Accept — post-ETL the operator inspects the imported
                 jurisdictions in the dedicated viewer (where map + stats + raster
                 overlay are all available together), then comes back here to
                 click Continue. Continue triggers apportionment.
                 P.1.1: the legacy inline ReviewIssuesSection moved into the
                 viewer's drill-down panels; Step 2 now just links over. -->
            <section v-if="lifecycle === 'done'" class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
                <div class="flex items-baseline justify-between mb-3">
                    <h2 class="text-white font-semibold">{{ t('c_setup.step2_map_data.review_heading', '4. Review & Accept') }}</h2>
                </div>
                <p class="text-gray-400 text-xs mb-3">
                    {{ t('c_setup.step2_map_data.review_body', 'The import finished. Open the jurisdiction viewer to inspect boundaries, populations, raster overlays, dual-footprint relationships, and the map health checks — then accept the map data there (planet scope). Click Continue below once accepted — that triggers apportionment.') }}
                </p>
                <p class="text-gray-500 text-[11px] mb-3" v-html="t('c_setup.step2_map_data.review_repair_note', 'Accepting closes the repair window, so work anything you intend to repair <span class=&quot;text-gray-400&quot;>before</span> you accept.')"></p>

                <!-- The map health scan, sitting directly above the findings it
                     produced. It ran as part of the pull, but it is measurement,
                     not ingestion — so it belongs to this section. -->
                <div v-if="scanState?.detectors?.length" class="mb-4 pb-4 border-b border-gray-800">
                    <ScanDetectorBars :detectors="scanState.detectors" />
                </div>

                <!-- Live open-flag state from the Data Review & Repair plane.
                     Continue is gated server-side on map acceptance, and the
                     acceptance in turn asks for acknowledgment while these
                     stay open — so the count is worth watching from here. -->
                <div v-if="flagState.loaded" class="mb-3">
                    <div v-if="flagState.open > 0"
                         class="rounded-md border border-amber-800/70 bg-amber-900/20 px-3 py-2 text-xs text-amber-200 flex items-center gap-2 flex-wrap">
                        <span class="relative group inline-flex items-center gap-1">
                            ⚑ {{ t('c_setup.step2_map_data.flag_count', { n: flagState.open, s: flagState.open === 1 ? '' : 's' }) }}
                            <span class="text-amber-400/70 text-[10px] cursor-help select-none">?</span>

                            <!-- The count alone reads as "the import failed N times",
                                 which it almost never means. At world scale most of
                                 these describe real geography — see lib/mapHealth.js
                                 for the per-check prose this summarises. -->
                            <div class="pointer-events-none absolute left-0 top-full mt-1 z-50 w-80 rounded bg-gray-700 border border-gray-600 p-2 text-[10px] text-gray-300 leading-snug hidden group-hover:block shadow-lg space-y-1 normal-case font-normal">
                                <div class="text-gray-200 font-semibold">{{ t('c_setup.step2_map_data.tooltip_title', 'These are statistics, not failures.') }}</div>
                                <div v-html="t('c_setup.step2_map_data.tooltip_p1', 'Seven checks measure the health of the imported map, the same way the district mapping tool measures population equality and shape compactness. Only <span class=&quot;text-red-300&quot;>orphaned rows</span> describes something the import got wrong.')"></div>
                                <div v-html="t('c_setup.step2_map_data.tooltip_p2', 'The rest describe the world: disputed borders that must coexist in one game space, island groups administered from a distant mainland, a national source that records the same village at two levels, a country the population raster doesn\'t cover. <span class=&quot;text-gray-400&quot;>Accepting is frequently the correct resolution.</span>')"></div>
                                <div class="pt-1 border-t border-gray-600 text-gray-400">
                                    {{ t('c_setup.step2_map_data.tooltip_p3', 'A large count is normal on a full-planet import. What matters is whether the structural check is clear — open the viewer and read each check\'s own explainer.') }}
                                </div>
                            </div>
                        </span>
                        <span v-if="flagState.critical > 0"
                              class="px-1.5 py-0 rounded text-[10px] bg-red-900 text-red-200 border border-red-700">
                            {{ t('c_setup.step2_map_data.flags_critical', { n: flagState.critical }) }}
                        </span>
                        <span v-if="flagState.warning > 0"
                              class="px-1.5 py-0 rounded text-[10px] bg-amber-900 text-amber-200 border border-amber-700">
                            {{ t('c_setup.step2_map_data.flags_warning', { n: flagState.warning }) }}
                        </span>
                        <span v-if="flagState.info > 0"
                              class="px-1.5 py-0 rounded text-[10px] bg-gray-700 text-gray-300 border border-gray-600">
                            {{ t('c_setup.step2_map_data.flags_info', { n: flagState.info }) }}
                        </span>
                    </div>
                    <div v-else class="text-xs text-emerald-300">
                        {{ t('c_setup.step2_map_data.map_health_clear', '✓ Map health clear — no open flags.') }}
                    </div>
                </div>

                <!-- Accept moved to the Continue slot (operator, 2026-08-04) —
                     one button, one place. What stays here is the way OUT to
                     the viewer, opened in a new tab so reviewing no longer
                     drops you out of setup and back at the top of the wizard. -->
                <div class="flex items-center gap-3">
                    <a href="/jurisdictions" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 px-3 py-1.5 rounded border bg-blue-900/40 border-blue-700 text-blue-200 hover:bg-blue-900/70 text-sm">
                        {{ t('c_setup.step2_map_data.review_viewer_link', 'Review in Jurisdiction Viewer ↗') }}
                    </a>
                    <span class="text-gray-500 text-xs">
                        {{ t('c_setup.step2_map_data.review_viewer_note', 'Opens in a new tab — setup stays where it is. Accept with the button at the bottom of this page.') }}
                    </span>
                </div>
            </section>

            <div class="flex justify-between pt-4 border-t border-gray-800 mt-4">
                <a href="/setup/step/1" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">
                    {{ t('c_setup.step2_map_data.back', '← Back') }}
                </a>
                <div class="flex flex-col items-end gap-1">
                    <!-- The single forward control: accepts first when the map
                         hasn't been accepted, then advances. Green while it
                         still carries the acceptance, blue once it is only
                         Continue — so the colour tells you which act you are
                         about to perform. -->
                    <!-- THE THREE ACTIVATION MODES (operator, 2026-08-08): the
                         ONE continue button stays the only continue control;
                         the dropdown beside it chooses what acceptance STARTS. -->
                    <div v-if="!mapAccepted" class="flex flex-col items-end gap-1.5">
                        <select v-model="scaleMode" :aria-label="t('c_setup.step2_map_data.activation_mode_aria', 'Activation mode')"
                                class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-xs text-white focus:outline-none focus:border-emerald-500">
                            <option v-for="o in MODE_OPTS" :key="o.v" :value="o.v">{{ o.t }}</option>
                        </select>
                        <label v-if="is_dev_world && scaleMode === 'eager'"
                               class="flex items-center gap-2 text-xs text-violet-300 select-none cursor-pointer">
                            <input type="checkbox" v-model="simulateAtScale" class="accent-violet-500" />
                            {{ t('c_setup.step2_map_data.dev_simulate', 'Dev: simulate the data at scale after the build (sandbox world only)') }}
                        </label>
                        <span class="text-[11px] text-gray-500 max-w-md text-right">
                            {{ scaleMode === 'eager'
                                ? t('c_setup.step2_map_data.mode_desc_eager', 'PREBUILD only: every legislature sized, every map drawn, institution shells provisioned. Simulated people, orgs & bills is the separate Dev option below.')
                                : scaleMode === 'population'
                                    ? t('c_setup.step2_map_data.mode_desc_population', 'Nothing pre-built — each place boots automatically as verified residents cross its threshold (5–9).')
                                    : t('c_setup.step2_map_data.mode_desc_manual', 'Nothing automatic — Activate per jurisdiction on the list, draw maps, build institutions through their forms; on sandbox worlds each activated row also gets a Simulate button.') }}
                        </span>
                    </div>
                    <button
                        type="button"
                        :disabled="advancing || accepting || !canAdvance || isRunning"
                        @click="continueFromStep2"
                        class="disabled:bg-gray-700 disabled:cursor-not-allowed text-white px-5 py-2 rounded-md font-semibold transition-colors"
                        :class="mapAccepted ? 'bg-blue-600 hover:bg-blue-500' : 'bg-emerald-700 hover:bg-emerald-600'"
                        :title="!canAdvance
                            ? t('c_setup.step2_map_data.continue_title_load', 'Load at least one nation (ADM1) before continuing')
                            : (mapAccepted ? '' : t('c_setup.step2_map_data.continue_title_accept', 'Accepting closes the repair window, then runs apportionment'))"
                    >
                        {{ continueLabel }}
                    </button>
                    <span v-if="apportioning" class="text-xs text-gray-500 italic">
                        {{ t('c_setup.step2_map_data.apportioning_note', 'Running cube-root apportionment across the jurisdiction tree…') }}
                    </span>
                    <!-- THE RE-HOOK: visible once accepted — starts (or resumes)
                         the deferred planet-wide build whenever the manual
                         mapping arc is done. Idempotent on the backend. -->
                    <div v-if="mapAccepted" class="flex items-center justify-end gap-2">
                        <button type="button" :disabled="rehooking" @click="startPlanetGeneration"
                                class="text-xs px-3 py-1.5 rounded border border-violet-600 text-violet-200 hover:bg-violet-900/40 disabled:opacity-50">
                            {{ rehooking ? t('c_setup.step2_map_data.btn_starting', 'Starting…') : t('c_setup.step2_map_data.btn_start_planet', 'Start planet-wide generation →') }}
                        </button>
                        <span v-if="rehookMsg" class="text-xs text-gray-400">{{ rehookMsg }}</span>
                    </div>
                    <!-- Map-acceptance gate 422: guidance, not failure — the
                         operator just hasn't accepted the map data yet. -->
                    <div v-if="acceptanceRequired"
                         class="rounded-md border border-amber-800/70 bg-amber-900/20 px-3 py-2 text-xs text-amber-200 max-w-sm text-right">
                        <p>{{ advanceError }}</p>
                        <!-- No duplicate Accept here any more: the button
                             directly above IS the accept-then-continue control
                             whenever the map is unaccepted. -->
                        <a href="/jurisdictions" target="_blank" rel="noopener"
                           class="inline-block mt-1.5 px-2 py-1 rounded border bg-blue-900/40 border-blue-700 text-blue-200 hover:bg-blue-900/70">
                            {{ t('c_setup.step2_map_data.review_first', 'Review first ↗') }}
                        </a>
                    </div>
                    <span v-else-if="advanceError" class="text-xs text-red-400 max-w-sm text-right">
                        {{ advanceError }}
                    </span>
                </div>
            </div>
    </div>
</template>
