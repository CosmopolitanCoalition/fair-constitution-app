<script setup>
import { computed, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Restore-from-backup panel — drop-in anywhere in the setup wizard.
 *
 * Picks a local .tar.gz produced by `MapDataExportService` and POSTs it to
 * `/api/import/jurisdictions`. The bundle's manifest decides which tables
 * are available; the operator picks a subset (default: everything). On
 * success the component navigates to `/setup` so the wizard's built-in
 * redirect chain lands the operator at the step matching the restored
 * `instance_settings.setup_step_completed`.
 *
 * Used by Step0 / Step1 / Step2 / Step3 — same behaviour across all of
 * them since the back-end accepts the same payload regardless of which
 * step the panel renders from.
 */

const props = defineProps({
    // Disable the controls (e.g. while an ETL run or other long-running
    // op is active). Step2 wires this to its `runOptionsDisabled` computed.
    disabled: { type: Boolean, default: false },
    // Optional title override for steps where "Restore from a backup" feels
    // off (e.g. Step0 prefers "Restore from a backup" but with a slightly
    // different lead-in).
    title:    { type: String,  default: 'Restore from a backup' },
})

// Available + selected tables. Populated from `/api/export/jurisdictions/tables`
// on mount so the list never goes stale relative to the back-end's TABLES
// constant. Defaults to everything checked.
const availableTables = ref([])
const selectedTables  = ref([])
const showTablePicker = ref(false)   // collapsed by default; opens on click

const importFile     = ref(null)     // File | null
const importing      = ref(false)
const importProgress = ref(0)        // 0–100 (upload phase only)
const importPhase    = ref('idle')   // idle | uploading | restoring | done | failed
const importError    = ref('')

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

function formatFile(f) {
    if (!f) return ''
    return `${f.name} (${formatBytes(f.size)})`
}

function onImportFile(event) {
    const f = event.target?.files?.[0] ?? null
    importFile.value  = f
    importError.value = ''
    importPhase.value = 'idle'
    importProgress.value = 0
}

function toggleTable(t) {
    const i = selectedTables.value.indexOf(t)
    if (i >= 0) selectedTables.value.splice(i, 1)
    else        selectedTables.value.push(t)
}

const allSelected  = computed(() => availableTables.value.length > 0 && selectedTables.value.length === availableTables.value.length)
const noneSelected = computed(() => selectedTables.value.length === 0)
function selectAll()   { selectedTables.value = [...availableTables.value] }
function selectNone()  { selectedTables.value = [] }

onMounted(async () => {
    try {
        const res = await fetch('/api/export/jurisdictions/tables', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (res.ok) {
            const data = await res.json()
            availableTables.value = Array.isArray(data.tables) ? data.tables : []
            selectedTables.value  = [...availableTables.value]   // default: everything
        }
    } catch (e) { /* leave defaults — the back-end will still accept null */ }
})

function startImport() {
    if (!importFile.value || importing.value) return
    importing.value      = true
    importError.value    = ''
    importPhase.value    = 'uploading'
    importProgress.value = 0

    const xhr  = new XMLHttpRequest()
    const form = new FormData()
    form.append('archive', importFile.value)
    // Encode the table selection as a JSON string so PHP can parse it as an
    // array on the server side without each value being a separate form key.
    form.append('tables', JSON.stringify(selectedTables.value))

    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            importProgress.value = Math.round((e.loaded / e.total) * 100)
            if (importProgress.value >= 100) importPhase.value = 'restoring'
        }
    })
    xhr.addEventListener('load', () => {
        importing.value = false
        let data = {}
        try { data = JSON.parse(xhr.responseText) } catch (e) { /* leave empty */ }
        if (xhr.status >= 200 && xhr.status < 300 && data.ok !== false) {
            importPhase.value = 'done'
            // Navigate to /setup — its index() reads instance_settings and
            // redirects to the right step based on the restored
            // setup_step_completed value, so the operator lands wherever the
            // bundle dictates.
            router.visit('/setup')
        } else {
            importPhase.value = 'failed'
            importError.value = data.error || `Restore failed (HTTP ${xhr.status})`
        }
    })
    xhr.addEventListener('error', () => {
        importing.value = false
        importPhase.value = 'failed'
        importError.value = 'Network error during upload'
    })
    xhr.open('POST', '/api/import/jurisdictions')
    xhr.setRequestHeader('X-CSRF-TOKEN', csrf())
    xhr.setRequestHeader('Accept', 'application/json')
    xhr.send(form)
}
</script>

<template>
    <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
        <h2 class="text-white font-semibold mb-2">{{ title }}</h2>
        <p class="text-gray-400 text-xs mb-4">
            Already have a <code class="text-gray-300">.tar.gz</code> exported
            from another instance? Upload it here to skip ahead. The backend
            validates the manifest's schema version before restoring — older
            snapshots are refused with a clear message. After a successful
            restore you'll land on whichever wizard step matches the bundle's
            saved progress (so a backup taken at Step 3 drops you back at Step 3).
        </p>

        <!-- Table picker — collapsed by default. Most operators want "everything",
             so the controls don't get in the way until they need them. -->
        <div v-if="availableTables.length" class="mb-3">
            <button type="button"
                    @click="showTablePicker = !showTablePicker"
                    class="text-[11px] text-gray-400 hover:text-gray-200 underline">
                {{ showTablePicker ? 'Hide' : 'Choose which tables to restore' }}
                ({{ selectedTables.length }} / {{ availableTables.length }})
            </button>
            <div v-if="showTablePicker"
                 class="mt-2 rounded border border-gray-800 bg-gray-950/40 p-3">
                <div class="flex items-center gap-2 mb-2">
                    <button type="button" @click="selectAll"
                            :disabled="allSelected"
                            class="text-[10px] px-2 py-0.5 rounded border bg-gray-800 border-gray-700 text-gray-300 hover:text-white disabled:opacity-50">
                        All
                    </button>
                    <button type="button" @click="selectNone"
                            :disabled="noneSelected"
                            class="text-[10px] px-2 py-0.5 rounded border bg-gray-800 border-gray-700 text-gray-300 hover:text-white disabled:opacity-50">
                        None
                    </button>
                    <span class="text-[10px] text-gray-500 italic ml-2">
                        Only tables actually present in the uploaded bundle get restored —
                        un-selected tables on this instance are left alone.
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                    <label v-for="t in availableTables" :key="t"
                           class="flex items-center gap-2 text-[11px] text-gray-200">
                        <input type="checkbox"
                               :value="t"
                               :checked="selectedTables.includes(t)"
                               @change="toggleTable(t)"
                               :disabled="disabled || importing" />
                        <code class="text-[10px] text-gray-300">{{ t }}</code>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <input type="file"
                   accept=".tar.gz,.tgz,application/gzip,application/x-gzip"
                   @change="onImportFile"
                   :disabled="disabled || importing"
                   class="text-xs text-gray-300 file:mr-3 file:px-3 file:py-1.5 file:rounded
                          file:border file:border-gray-700 file:bg-gray-800 file:text-gray-200
                          file:hover:bg-gray-700 file:cursor-pointer
                          disabled:opacity-50 disabled:cursor-not-allowed" />
            <button type="button"
                    @click="startImport"
                    :disabled="!importFile || disabled || importing || noneSelected"
                    class="bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700
                           text-white px-4 py-1.5 rounded text-sm font-semibold">
                {{ importing ? (importPhase === 'uploading' ? 'Uploading…' : 'Restoring…') : 'Upload & restore' }}
            </button>
            <span v-if="importFile && !importing" class="text-xs text-gray-500">
                {{ formatFile(importFile) }}
            </span>
        </div>

        <!-- Upload progress bar — only during upload phase. -->
        <div v-if="importing" class="mt-3">
            <div class="h-2 bg-gray-800 rounded overflow-hidden">
                <div class="h-2 bg-emerald-600 transition-all duration-200"
                     :style="{ width: importPhase === 'uploading' ? (importProgress + '%') : '100%' }"></div>
            </div>
            <div class="text-[11px] text-gray-400 mt-1">
                <template v-if="importPhase === 'uploading'">
                    Uploading… {{ importProgress }}%
                </template>
                <template v-else-if="importPhase === 'restoring'">
                    Upload complete — restoring database (pg_restore). May take several
                    minutes on a full-rasters bundle.
                </template>
            </div>
        </div>

        <div v-if="importPhase === 'done' && !importing" class="mt-3 text-xs text-emerald-300">
            Restore complete. Continuing…
        </div>
        <div v-if="importError" class="mt-3 text-xs text-red-400">
            {{ importError }}
        </div>
    </section>
</template>
