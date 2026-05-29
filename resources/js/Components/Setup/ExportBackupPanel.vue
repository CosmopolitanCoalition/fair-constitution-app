<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * Export-to-backup panel — drop-in for Step 4 (Confirm) and future admin
 * sections. Mirrors the ImportBackupPanel structure (single Start button,
 * recent-runs list, live progress bar + ETA, halt button) but doesn't
 * expose selective tables — an export is always the full FK-downstream
 * graph of jurisdictions plus settings + rasters, because anything less
 * would leave the receiving instance with phantom-empty children after
 * its TRUNCATE jurisdictions CASCADE pass.
 *
 * Listing endpoint surfaces in-progress + completed builds; polling
 * cadence is 2s while anything is running so the bar + ETA stay close to
 * real time.
 */

defineProps({
    // Optional title override.
    title: { type: String, default: 'Export current data' },
})

const exportStarting   = ref(false)
const exportsList      = ref([])
const exportError      = ref('')
let exportsPollTimer   = null
// Wall-clock timestamp until which we keep polling even when no running
// entry is visible yet. Set on dispatch so a brand-new job that hasn't
// written its status.json yet (queued in Redis waiting for Horizon) still
// appears within a couple of polls without manual refresh.
const exportsPollUntil = ref(0)

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
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

function formatEta(seconds) {
    if (seconds == null || seconds < 0) return '—'
    if (seconds < 60)        return `${seconds}s`
    if (seconds < 3600)      return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    return `${h}h ${m}m`
}
function formatThroughput(bps) {
    if (!bps || bps <= 0) return ''
    if (bps < 1024)             return `${bps} B/s`
    if (bps < 1024 * 1024)      return `${(bps / 1024).toFixed(0)} KB/s`
    if (bps < 1024 ** 3)        return `${(bps / 1024 / 1024).toFixed(1)} MB/s`
    return `${(bps / 1024 ** 3).toFixed(2)} GB/s`
}
function exportPercent(e) {
    const p = e.progress
    if (!p) return null
    if (p.phase === 'compressing') return 100
    if (!p.bytes_written || !p.estimated_total_bytes) return null
    return Math.min(100, Math.max(0, (p.bytes_written / p.estimated_total_bytes) * 100))
}

async function refreshExports() {
    try {
        const res = await fetch('/api/export/jurisdictions/list', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) return
        const data = await res.json()
        exportsList.value = Array.isArray(data.exports) ? data.exports : []
    } catch (e) { /* silent — poll retries */ }
}

function ensureExportPolling() {
    const anyRunning   = exportsList.value.some(e => e.status === 'running')
    const stillCooking = Date.now() < exportsPollUntil.value
    if ((anyRunning || stillCooking) && !exportsPollTimer) {
        exportsPollTimer = setInterval(refreshExports, 2000)
    } else if (!anyRunning && !stillCooking && exportsPollTimer) {
        clearInterval(exportsPollTimer)
        exportsPollTimer = null
    }
}
watch(exportsList, ensureExportPolling, { deep: true })

async function startExport() {
    exportStarting.value = true
    exportError.value    = ''
    try {
        // Single-button export = full bundle. The back-end's
        // MapDataExportService::TABLES drives what's actually dumped;
        // omitting `tables` from the request body means "everything".
        const form = new FormData()
        form.append('async', '1')
        const res = await fetch('/api/export/jurisdictions', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: form,
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            exportError.value = data.error || `export start failed (HTTP ${res.status})`
            return
        }
        // Keep polling for ~30s even if the listing comes back empty —
        // Horizon may take a moment to dequeue the job and write status.
        exportsPollUntil.value = Date.now() + 30_000
        await refreshExports()
        ensureExportPolling()
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

async function haltExport(exportId) {
    try {
        await fetch(`/api/export/jurisdictions/${exportId}/halt`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
        })
        await refreshExports()
    } catch (e) { /* ignore */ }
}

onMounted(() => { refreshExports() })
onBeforeUnmount(() => {
    if (exportsPollTimer) { clearInterval(exportsPollTimer); exportsPollTimer = null }
})
</script>

<template>
    <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-white font-semibold mb-2">{{ title }}</h2>
        <p class="text-gray-400 text-xs mb-4">
            Build a portable <code class="text-gray-300">.tar.gz</code> snapshot of
            this instance — every table that's part of the FK-downstream graph of
            jurisdictions, plus settings and rasters. Restoring this bundle on
            another instance via Step 0's <em>Restore from a backup</em> panel
            reproduces the full state, including district maps. Runs async via
            Horizon; large exports (full rasters) take 20–40 minutes.
        </p>

        <div class="flex items-center gap-3 mb-3">
            <button type="button"
                    @click="startExport"
                    :disabled="exportStarting"
                    class="bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700
                           text-white px-4 py-1.5 rounded text-sm font-semibold">
                {{ exportStarting ? 'Starting…' : 'Start full export' }}
            </button>
            <span v-if="exportError" class="text-xs text-red-400">{{ exportError }}</span>
        </div>

        <!-- In-progress + completed list -->
        <div v-if="exportsList.length" class="rounded border border-gray-800 bg-gray-950/40">
            <div class="px-3 py-2 text-xs text-gray-400 border-b border-gray-800">
                Recent exports
            </div>
            <div class="divide-y divide-gray-800">
                <div v-for="e in exportsList" :key="e.export_id"
                     class="px-3 py-2 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="font-mono text-gray-300 truncate">{{ e.export_id }}</div>
                            <div class="text-[10px] text-gray-500">
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
                            <span v-else-if="e.status === 'halted'"
                                  class="text-[10px] px-2 py-0.5 rounded bg-amber-900 text-amber-200 border border-amber-700">
                                halted
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
                            <button v-if="e.status === 'running'"
                                    type="button"
                                    @click="haltExport(e.export_id)"
                                    class="text-[11px] px-2 py-0.5 rounded bg-amber-900/50 border border-amber-700 text-amber-200 hover:bg-amber-900">
                                ⏹ halt
                            </button>
                            <button v-if="e.status !== 'running'"
                                    type="button"
                                    @click="deleteExport(e.export_id)"
                                    class="text-[11px] text-gray-500 hover:text-red-400">
                                delete
                            </button>
                        </div>
                    </div>

                    <!-- Live progress bar — running entries with a progress snapshot. -->
                    <div v-if="e.status === 'running' && e.progress" class="mt-2">
                        <div class="h-1.5 bg-gray-800 rounded overflow-hidden">
                            <div class="h-1.5 bg-blue-600 transition-all duration-300"
                                 :style="{ width: (exportPercent(e) ?? 5) + '%' }"></div>
                        </div>
                        <div class="text-[10px] text-gray-400 mt-1 flex flex-wrap gap-x-3">
                            <span v-if="exportPercent(e) != null">
                                {{ exportPercent(e).toFixed(1) }}%
                            </span>
                            <span v-if="e.progress.bytes_written">
                                {{ formatBytes(e.progress.bytes_written) }}<template v-if="e.progress.estimated_total_bytes"> / ~{{ formatBytes(e.progress.estimated_total_bytes) }}</template>
                            </span>
                            <span v-if="e.progress.throughput_bps">
                                {{ formatThroughput(e.progress.throughput_bps) }}
                            </span>
                            <span v-if="e.progress.eta_seconds != null && e.progress.eta_seconds > 0">
                                ETA: {{ formatEta(e.progress.eta_seconds) }}
                            </span>
                            <span v-if="e.progress.phase === 'compressing'" class="text-amber-300">
                                compressing archive…
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
