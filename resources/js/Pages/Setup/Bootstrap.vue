<script setup>
/**
 * Phase M — WordPress-style self-bootstrap page.
 *
 * Two sections:
 *   1. Schema status card  — apply pending migrations
 *   2. Continue button     — enabled once schema is up to date; navigates
 *                            back to /setup, which then routes to the
 *                            appropriate wizard step.
 *
 * Founder/first-user registration is intentionally NOT here. Per the
 * constitutional model, the first user is created at the END of setup, not
 * the beginning — the wizard is open to whoever's running the install
 * machine, and the constitutional founder gets registered when they finish.
 *
 * Same page handles initial install AND future delta migrations — the only
 * difference is whether the schema-status card needs the operator to click
 * "Apply" or just shows "Up to date" already.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    status: { type: Object, required: true },
})

// Reactive status — replaced after each action so the gating updates live.
const status = ref(props.status)

const submittingMigration = ref(false)
const migrationOutput     = ref(null)

const csrfToken = computed(() =>
    document.querySelector('meta[name="csrf-token"]')?.content ?? ''
)

async function refreshStatus() {
    try {
        const res = await fetch('/api/setup/bootstrap/status', {
            headers:     { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        const data = await res.json()
        status.value = data
    } catch (e) {
        // Stale status is acceptable — operator can refresh manually.
    }
}

async function runMigrations() {
    submittingMigration.value = true
    migrationOutput.value     = null
    try {
        const res = await fetch('/api/setup/bootstrap/migrate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken.value,
            },
        })
        const data = await res.json().catch(() => ({}))
        migrationOutput.value = data.output || data.error || 'Unknown response.'
        if (data.status) {
            status.value = data.status
        } else {
            await refreshStatus()
        }
    } catch (e) {
        migrationOutput.value = e.message
    } finally {
        submittingMigration.value = false
    }
}

function continueToWizard() {
    // /setup re-routes to the next incomplete step, or home if setup is done.
    router.visit('/setup')
}

const schemaCardLabel = computed(() => {
    const s = status.value
    if (s.schema_state === 'uninitialised') {
        return `Database not initialized — ${s.pending_count} migration${s.pending_count === 1 ? '' : 's'} to apply`
    }
    if (s.schema_state === 'pending') {
        return `${s.pending_count} migration${s.pending_count === 1 ? '' : 's'} pending`
    }
    return 'Schema is up to date'
})

const schemaCardOk = computed(() => status.value.schema_state === 'up_to_date')
</script>

<template>
    <AppLayout :hide-nav="true">
        <div class="max-w-3xl mx-auto w-full px-6 py-12 space-y-8">
            <header>
                <h1 class="text-3xl font-bold text-white">Database Setup</h1>
                <p class="text-gray-400 mt-2">
                    Apply schema migrations to prepare the instance. The wizard then walks you through configuring the constitution, loading map data, and seating institutions.
                </p>
            </header>

            <!-- ─── Section 1: Schema status ─── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xl font-semibold text-white">Schema</h2>
                    <span v-if="schemaCardOk" class="text-emerald-400 text-sm">✓ Up to date</span>
                    <span v-else class="text-amber-400 text-sm">Updates required</span>
                </div>

                <p class="text-gray-300">{{ schemaCardLabel }}</p>

                <div v-if="status.pending_count > 0" class="mt-3">
                    <details class="text-sm text-gray-400">
                        <summary class="cursor-pointer hover:text-white select-none">
                            Show pending migrations ({{ status.pending_count }})
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
                    {{ submittingMigration ? 'Applying…' : 'Apply schema updates' }}
                </button>

                <p v-if="status.etl_running" class="mt-2 text-xs text-amber-400">
                    Disabled while an ETL run is in progress.
                </p>

                <pre
                    v-if="migrationOutput"
                    class="mt-4 p-3 bg-black rounded text-xs text-gray-300 overflow-x-auto whitespace-pre-wrap"
                >{{ migrationOutput }}</pre>
            </section>

            <!-- ─── Section 2: Continue ─── -->
            <section v-if="status.ready" class="flex justify-end">
                <button
                    @click="continueToWizard"
                    class="px-6 py-3 bg-emerald-600 hover:bg-emerald-500 text-white rounded-md font-semibold transition"
                >
                    Continue to wizard →
                </button>
            </section>
        </div>
    </AppLayout>
</template>
