<script setup>
/**
 * Phase M — WordPress-style self-bootstrap page.
 *
 * Three sections:
 *   1. Schema status card — apply pending migrations.
 *   2. Operator account   — create the founder / physical-operator credentials. (Roles-campaign
 *                           Phase 1: the operator account is the FIRST thing set up, BEFORE the
 *                           SOLO/JOIN fork — operator onboarding is blended into the start of setup.
 *                           This intentionally overrides the older "first user registered at the END
 *                           of setup" model: the operator's physical credentials come first, then they
 *                           decide whether to start a new world or join an existing mesh.)
 *   3. Continue button    — enabled once the schema is up to date AND the operator account exists;
 *                           navigates to /setup, which routes to the SOLO/JOIN fork.
 *
 * Same page handles initial install AND future delta migrations.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

const props = defineProps({
    status: { type: Object, required: true },
})

// Reactive status — replaced after each action so the gating updates live.
const status = ref(props.status)

const submittingMigration = ref(false)
const migrationOutput     = ref(null)

// Operator-account form.
const founderName            = ref('')
const founderEmail           = ref('')
const founderPassword        = ref('')
const founderPasswordConfirm = ref('')
const creatingFounder        = ref(false)
const founderError           = ref(null)

const csrfToken = computed(() =>
    document.querySelector('meta[name="csrf-token"]')?.content ?? ''
)

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
        const res = await fetch('/api/setup/bootstrap/migrate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken.value },
        })
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

const canCreateFounder = computed(() =>
    !!founderName.value.trim() &&
    !!founderEmail.value.trim() &&
    founderPassword.value.length >= 8 &&
    founderPassword.value === founderPasswordConfirm.value
)

async function createFounder() {
    if (!canCreateFounder.value || creatingFounder.value) return
    creatingFounder.value = true
    founderError.value    = null
    try {
        const res = await fetch('/api/setup/bootstrap/create-founder', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify({
                name: founderName.value.trim(),
                email: founderEmail.value.trim(),
                password: founderPassword.value,
                password_confirmation: founderPasswordConfirm.value,
            }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            founderError.value = data.error || data.message || 'Could not create the operator account.'
            return
        }
        if (data.status) status.value = data.status
        else await refreshStatus()
    } catch (e) {
        founderError.value = e.message
    } finally {
        creatingFounder.value = false
    }
}

function continueToWizard() {
    // /setup re-routes to the SOLO/JOIN fork (or the next step / home).
    router.visit('/setup')
}

const schemaCardLabel = computed(() => {
    const s = status.value
    if (s.schema_state === 'uninitialised') {
        // Fresh install: the complete schema loads in one step (a flattened
        // baseline dump + any migrations newer than it). Never enumerate
        // internals to a first-time user.
        return 'Fresh database — the complete schema installs in one step.'
    }
    if (s.schema_state === 'pending') {
        return `${s.pending_count} schema update${s.pending_count === 1 ? '' : 's'} available`
    }
    return 'Schema is up to date'
})

const isFreshInstall = computed(() => status.value.schema_state === 'uninitialised')

const schemaCardOk = computed(() => status.value.schema_state === 'up_to_date')
const hasFounder   = computed(() => !!status.value.has_founder)
const canContinue  = computed(() => schemaCardOk.value && hasFounder.value)
</script>

<template>
    <div class="max-w-3xl mx-auto w-full px-6 py-12 space-y-8">
        <header>
            <h1 class="text-3xl font-bold text-white">Set up this node</h1>
            <p class="text-gray-400 mt-2">
                Set up the database, then create your operator account. The wizard then asks whether to
                start a new world or join an existing mesh.
            </p>
        </header>

        <!-- ─── Section 1: Schema status ─── -->
        <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xl font-semibold text-white">1 · Schema</h2>
                <span v-if="schemaCardOk" class="text-emerald-400 text-sm">✓ Up to date</span>
                <span v-else class="text-amber-400 text-sm">Updates required</span>
            </div>

            <p class="text-gray-300">{{ schemaCardLabel }}</p>

            <!-- Update details are for operators diagnosing an EXISTING box; a
                 fresh install never shows internals. -->
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

        <!-- ─── Section 2: Operator account (after the schema is ready) ─── -->
        <section v-if="schemaCardOk" class="bg-gray-900 border border-gray-800 rounded-lg p-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xl font-semibold text-white">2 · Operator account</h2>
                <span v-if="hasFounder" class="text-emerald-400 text-sm">✓ Created</span>
            </div>

            <template v-if="!hasFounder">
                <p class="text-gray-400 text-sm mb-4">
                    Your physical-operator credentials. This is the account that runs and governs this node;
                    you'll choose to start a world or join a mesh next.
                </p>
                <div class="space-y-3">
                    <input v-model="founderName" type="text" placeholder="Your name"
                        class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
                    <input v-model="founderEmail" type="email" placeholder="Email"
                        class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
                    <input v-model="founderPassword" type="password" placeholder="Password (8+ characters)"
                        class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
                    <input v-model="founderPasswordConfirm" type="password" placeholder="Confirm password"
                        class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
                </div>
                <div v-if="founderError" class="mt-3 text-sm text-red-400">{{ founderError }}</div>
                <button
                    :disabled="!canCreateFounder || creatingFounder"
                    @click="createFounder"
                    class="mt-4 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700 disabled:text-gray-500 text-white rounded-md transition"
                >
                    {{ creatingFounder ? 'Creating…' : 'Create operator account' }}
                </button>
            </template>
            <p v-else class="text-gray-400 text-sm">Operator account is set. Continue to choose solo or join.</p>
        </section>

        <!-- ─── Section 3: Continue ─── -->
        <section v-if="canContinue" class="flex justify-end">
            <button
                @click="continueToWizard"
                class="px-6 py-3 bg-emerald-600 hover:bg-emerald-500 text-white rounded-md font-semibold transition"
            >
                Continue →
            </button>
        </section>
    </div>
</template>
