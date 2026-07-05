<script setup>
/**
 * Phase M — WordPress-style self-bootstrap page. SCHEMA ONLY.
 *
 * Per the operator's founding-order ruling (2026-07-05): the first question
 * after the code is live is JOIN-or-START, not the operator account. So this
 * page does one job — get the database schema in place — then hands off to the
 * fork (/setup → /setup/mode). The operator account, node address and roles are
 * the operator-setup step that comes AFTER the fork (on the START path).
 *
 * Same page also handles future delta migrations on an existing box.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { csrfFetch } from '@/lib/csrf'

defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

const props = defineProps({
    status: { type: Object, required: true },
})

const status = ref(props.status)
const submittingMigration = ref(false)
const migrationOutput     = ref(null)

async function refreshStatus() {
    try {
        const res = await fetch('/api/setup/bootstrap/status', {
            headers:     { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        status.value = await res.json()
    } catch (e) {
        // Stale status is acceptable — operator can refresh manually.
    }
}

async function runMigrations() {
    submittingMigration.value = true
    migrationOutput.value     = null
    try {
        const res = await csrfFetch('/api/setup/bootstrap/migrate', { method: 'POST' })
        const data = await res.json().catch(() => ({}))
        migrationOutput.value = data.output || data.error || 'Unknown response.'
        if (data.status) status.value = data.status
        else await refreshStatus()
    } catch (e) {
        migrationOutput.value = e.message
    } finally {
        submittingMigration.value = false
    }
}

function continueToFork() {
    // /setup routes to the JOIN-or-START fork.
    router.visit('/setup')
}

const schemaCardLabel = computed(() => {
    const s = status.value
    if (s.schema_state === 'uninitialised') {
        return 'Fresh database — the complete schema installs in one step.'
    }
    if (s.schema_state === 'pending') {
        return `${s.pending_count} schema update${s.pending_count === 1 ? '' : 's'} available`
    }
    return 'Schema is up to date'
})

const isFreshInstall = computed(() => status.value.schema_state === 'uninitialised')
const schemaCardOk   = computed(() => status.value.schema_state === 'up_to_date')
</script>

<template>
    <div class="max-w-3xl mx-auto w-full px-6 py-12 space-y-8">
        <header>
            <h1 class="text-3xl font-bold text-white">Set up this node</h1>
            <p class="text-gray-400 mt-2">
                First, set up the database. Then you'll choose whether to start a new world or join an
                existing mesh — and, if you're starting fresh, create your operator account.
            </p>
        </header>

        <!-- ─── Schema ─── -->
        <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xl font-semibold text-white">1 · Database</h2>
                <span v-if="schemaCardOk" class="text-emerald-400 text-sm">✓ Ready</span>
                <span v-else class="text-amber-400 text-sm">Setup required</span>
            </div>

            <p class="text-gray-300">{{ schemaCardLabel }}</p>

            <!-- Update details are for operators diagnosing an EXISTING box; a fresh install never shows internals. -->
            <div v-if="status.schema_state === 'pending' && status.pending_count > 0" class="mt-3">
                <details class="text-sm text-gray-400">
                    <summary class="cursor-pointer hover:text-white select-none">
                        Show update details ({{ status.pending_count }})
                    </summary>
                    <ul class="mt-2 ml-4 space-y-1 font-mono text-xs">
                        <li v-for="m in status.pending_migrations" :key="m">{{ m }}</li>
                    </ul>
                </details>
            </div>

            <button
                v-if="!schemaCardOk"
                :disabled="submittingMigration || status.etl_running"
                @click="runMigrations"
                class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 disabled:text-gray-500 text-white rounded-md transition"
            >
                {{ submittingMigration
                    ? (isFreshInstall ? 'Setting up…' : 'Applying…')
                    : (isFreshInstall ? 'Set up the database' : 'Apply schema updates') }}
            </button>

            <p v-if="status.etl_running" class="mt-2 text-xs text-amber-400">
                Disabled while an ETL run is in progress.
            </p>

            <pre
                v-if="migrationOutput"
                class="mt-4 p-3 bg-black rounded text-xs text-gray-300 overflow-x-auto whitespace-pre-wrap"
            >{{ migrationOutput }}</pre>
        </section>

        <!-- ─── Continue to the fork ─── -->
        <section v-if="schemaCardOk" class="flex justify-end">
            <button
                @click="continueToFork"
                class="px-6 py-3 bg-emerald-600 hover:bg-emerald-500 text-white rounded-md font-semibold transition"
            >
                Continue →
            </button>
        </section>
    </div>
</template>
