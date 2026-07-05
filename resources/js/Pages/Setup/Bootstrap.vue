<script setup>
/**
 * Phase M — WordPress-style self-bootstrap page.
 *
 * Sections:
 *   1. Schema status card    — apply pending migrations.
 *   2. Operator account      — create the founder / physical-operator credentials. (Roles-campaign
 *                              Phase 1: the operator account is the FIRST thing set up, BEFORE the
 *                              SOLO/JOIN fork — operator onboarding is blended into the start of setup.
 *                              This intentionally overrides the older "first user registered at the END
 *                              of setup" model: the operator's physical credentials come first, then they
 *                              decide whether to start a new world or join an existing mesh.)
 *   2b. Operator profile     — AFTER the founder account exists: name this node and set the peer-reachable
 *                              address, and surface the operator plane (/operator). Optional to complete,
 *                              so it never hard-blocks reaching /setup.
 *   3. Share this deployment — download pre-baked start scripts (solo / join, Windows / Unix) so a
 *                              colleague can found their own world or mirror this one.
 *   4. Continue button       — enabled once the schema is up to date AND the operator account exists;
 *                              navigates to /setup, which routes to the SOLO/JOIN fork.
 *
 * Same page handles initial install AND future delta migrations.
 *
 * CSRF: every POST here goes through csrfFetch — create-founder rotates the session (a new user logs
 * in), which stales the <meta name="csrf-token"> tag; csrfFetch reads the fresh XSRF cookie per attempt
 * and retries once on 419, killing the "token error, refresh fixed it" bug for the pages that follow.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { csrfFetch } from '@/lib/csrf'

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

// Operator-profile form (instance identity — filled after the account exists).
const instanceName    = ref('')
const selfUrl         = ref('')
const savingProfile   = ref(false)
const profileError    = ref(null)
const profileSaved    = ref(false)
const restartRequired = ref(false)

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
        const res = await csrfFetch('/api/setup/bootstrap/migrate', {
            method: 'POST',
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
        const res = await csrfFetch('/api/setup/bootstrap/create-founder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
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

// Operator profile is optional — the Save button lights up if EITHER field has
// content, so a partial fill (name only, address later) is fine.
const canSaveProfile = computed(() =>
    !!instanceName.value.trim() || !!selfUrl.value.trim()
)

async function saveProfile() {
    if (!canSaveProfile.value || savingProfile.value) return
    savingProfile.value = true
    profileError.value  = null
    profileSaved.value  = false
    try {
        const res = await csrfFetch('/api/setup/operator/profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                instance_name: instanceName.value.trim() || null,
                self_url: selfUrl.value.trim() || null,
            }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            profileError.value = data.error || data.message || 'Could not save the operator profile.'
            return
        }
        profileSaved.value    = true
        restartRequired.value = !!data.restart_required
        // Reflect any server-normalised values back into the fields.
        if (data.settings) {
            if (data.settings.instance_name) instanceName.value = data.settings.instance_name
            if (data.settings.self_url) selfUrl.value = data.settings.self_url
        }
    } catch (e) {
        profileError.value = e.message
    } finally {
        savingProfile.value = false
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

// Deploy-package downloads are gated behind an authenticated session, which
// only exists once the founder account is created (create-founder logs them in).
// The join kind is only meaningful once a peer-reachable address is on file.
function deployPackageUrl(os, kind) {
    return `/api/setup/deploy-package?os=${os}&kind=${kind}`
}
function downloadPackage(os, kind) {
    // A plain navigation triggers the attachment download; the auth cookie rides along.
    window.location.href = deployPackageUrl(os, kind)
}
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
            <p v-else class="text-gray-400 text-sm">Operator account is set. Name your node below, then continue to choose solo or join.</p>
        </section>

        <!-- ─── Section 2b: Operator profile — instance identity + the operator plane ─── -->
        <section v-if="hasFounder" class="bg-gray-900 border border-gray-800 rounded-lg p-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xl font-semibold text-white">3 · Name this node</h2>
                <span v-if="profileSaved" class="text-emerald-400 text-sm">✓ Saved</span>
                <span v-else class="text-gray-500 text-xs">optional — set later if you prefer</span>
            </div>

            <p class="text-gray-400 text-sm mb-4">
                Give this box a friendly label and the address peers dial to reach it. You can leave the
                address blank now and set it later — but a colleague can only <em>join</em> this world once
                it's on file.
            </p>

            <div class="space-y-3">
                <label class="block">
                    <span class="block text-xs text-gray-400 mb-1">Instance name</span>
                    <input v-model="instanceName" type="text" placeholder="e.g. Manhattan node"
                        class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
                </label>
                <label class="block">
                    <span class="block text-xs text-gray-400 mb-1">Peer-reachable address (self-URL)</span>
                    <input v-model="selfUrl" type="url" placeholder="http://192.168.1.20:8080"
                        class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
                    <span class="block text-xs text-gray-500 mt-1">
                        How other nodes dial this box to sync. Use the LAN/public address others can reach, not localhost.
                    </span>
                </label>
            </div>

            <div v-if="profileError" class="mt-3 text-sm text-red-400">{{ profileError }}</div>
            <div v-if="restartRequired" class="mt-3 text-xs text-amber-400 bg-amber-900/20 border border-amber-800/50 rounded p-2">
                The peer-reachable address changed. Re-run the start command
                (<code class="text-amber-300">docker compose up -d</code>) so the containers pick it up before peers try to join.
            </div>

            <button
                :disabled="!canSaveProfile || savingProfile"
                @click="saveProfile"
                class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 disabled:text-gray-500 text-white rounded-md transition"
            >
                {{ savingProfile ? 'Saving…' : 'Save node identity' }}
            </button>

            <!-- Operator role: surface the operator plane; do NOT rebuild the roles FSM here. -->
            <div class="mt-6 pt-5 border-t border-gray-800">
                <h3 class="text-sm font-semibold text-gray-200 mb-1">Operator role</h3>
                <p class="text-gray-400 text-sm">
                    Your node also carries an <strong>operator role</strong> on the mesh — a capability, not a
                    governed office. <strong>Record Keeper</strong> is the recommended first-node role: it holds the
                    public record and is self-asserted, so you turn it on yourself with no vote. Other roles
                    (Archivist, Social Moderator, Identity Broker) are governed and must be requested, then approved.
                </p>
                <a href="/operator/roles"
                    class="inline-flex items-center gap-1 mt-3 text-sm text-blue-400 hover:text-blue-300">
                    Open the operator console →
                </a>
                <p class="text-xs text-gray-600 mt-1">
                    You can set your operator role now or any time from the console at <code class="text-gray-500">/operator</code>.
                </p>
            </div>
        </section>

        <!-- ─── Section: Share this deployment ─── -->
        <section v-if="hasFounder" class="bg-gray-900 border border-gray-800 rounded-lg p-6">
            <h2 class="text-xl font-semibold text-white mb-3">Share this deployment</h2>
            <p class="text-gray-400 text-sm mb-5">
                Hand a colleague a one-file start script so they can stand up a box in one step. Pick their
                operating system and whether they should found their own world or mirror this one.
            </p>

            <div class="grid sm:grid-cols-2 gap-5">
                <div class="bg-gray-950 border border-gray-800 rounded-lg p-4">
                    <div class="text-emerald-400 text-xs font-semibold tracking-wide uppercase mb-1">Solo</div>
                    <p class="text-gray-400 text-xs mb-3">
                        A colleague founds <strong>their own world</strong> of the same shape — ports and game mode pre-seeded.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="downloadPackage('windows', 'solo')"
                            class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-100 text-xs rounded transition">
                            Windows
                        </button>
                        <button type="button" @click="downloadPackage('unix', 'solo')"
                            class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-100 text-xs rounded transition">
                            macOS / Linux
                        </button>
                    </div>
                </div>

                <div class="bg-gray-950 border border-gray-800 rounded-lg p-4">
                    <div class="text-sky-400 text-xs font-semibold tracking-wide uppercase mb-1">Join</div>
                    <p class="text-gray-400 text-xs mb-3">
                        A colleague <strong>mirrors this world</strong> — pre-baked with this box's address and a fresh join key.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="downloadPackage('windows', 'join')"
                            class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-100 text-xs rounded transition">
                            Windows
                        </button>
                        <button type="button" @click="downloadPackage('unix', 'join')"
                            class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-100 text-xs rounded transition">
                            macOS / Linux
                        </button>
                    </div>
                    <p v-if="!selfUrl.trim()" class="text-xs text-amber-400 mt-3">
                        Set the peer-reachable address above first, or the join script won't know where to dial.
                    </p>
                </div>
            </div>
        </section>

        <!-- ─── Continue ─── -->
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
