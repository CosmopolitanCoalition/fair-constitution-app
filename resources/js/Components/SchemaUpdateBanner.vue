<script setup>
/**
 * Phase M — global "schema update available" banner.
 *
 * Polls /api/setup/bootstrap/status on mount and shows a yellow banner with
 * an "Apply updates" button when migrations are pending against an already-
 * initialised schema (the delta-update flow).
 *
 * Initial-install case (schema_state === 'uninitialised') is handled by the
 * SetupController gates that redirect /setup straight to /setup/bootstrap,
 * so we don't duplicate the banner there.
 */
import { computed, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const status  = ref(null)
const loading = ref(true)

onMounted(async () => {
    try {
        const res = await fetch('/api/setup/bootstrap/status', {
            headers:     { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        if (res.ok) {
            status.value = await res.json()
        }
    } catch (e) {
        // Silent — DB might not be reachable yet on a fresh install.
    } finally {
        loading.value = false
    }
})

const visible = computed(() => {
    const s = status.value
    if (!s) return false
    return s.schema_state === 'pending' && s.pending_count > 0
})

const pendingLabel = computed(() => {
    const n = status.value?.pending_count || 0
    return `${n} schema update${n === 1 ? '' : 's'} available`
})

function applyUpdates() {
    router.visit('/setup/bootstrap')
}
</script>

<template>
    <div
        v-if="visible"
        class="bg-amber-900/95 border-b border-amber-700 px-4 py-2 text-sm text-amber-100 flex items-center justify-between gap-3 shrink-0"
    >
        <div>
            <span class="font-semibold">Required:</span>
            {{ pendingLabel }} — apply before starting an ETL run.
        </div>
        <button
            @click="applyUpdates"
            class="px-3 py-1 bg-amber-700 hover:bg-amber-600 text-amber-50 rounded text-xs font-medium transition"
        >
            Apply updates
        </button>
    </div>
</template>
