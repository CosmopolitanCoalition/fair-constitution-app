<script setup>
/**
 * Operator-setup step — the START-path "operator console at bootstrap"
 * (operator ruling 2026-07-05). Comes AFTER the JOIN-or-START fork, BEFORE the
 * cosmic address. Wave 2 rework to the FIVE-STEP wizard of the design
 * contract (mockups/v3/operator/setup.html):
 *   0 Claim your account — Path A (fresh local credentials) beside the Path-B
 *     device-key card. TRUE pre-account linking needs a pre-auth endpoint the
 *     backend deliberately does not have (POST /operator/link requires an
 *     operator session) — the card says so honestly and points at
 *     /operator/identity, never simulating the flow.
 *   1 Name the instance — node name + reach/address (unchanged behavior).
 *   2 Pick a role — the FOUR NAMED-ROLE CARDS (fixtures-operator.js copy):
 *     a friendly grouping over the 9-channel capability substrate, no new
 *     power. Choosing a role establishes its channels via the SAME
 *     /api/setup/operator/roles/establish endpoint (founding self-assert).
 *   3 Role-specific setup — the per-channel substrate view, needs-setup
 *     notes + config links + turn-on-all (the pre-rework section 3).
 *   4 You're set — deploy packages + Continue (→ cosmic for solo, → join).
 */
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import { csrfFetch } from '@/lib/csrf'

defineOptions({
    // ShellV2 (operator, 2026-08-04). Setup ran on the v1 shell with
    // `chrome: 'minimal'`, which is why it had NO bottom command bar and the
    // OLD dev controls: CmdBar and the Dev* panels are ShellV2 components, so
    // a v1 setup page could never receive either. Menus that cannot work yet
    // are locked by MenuNav while instance.setupComplete is false.
    layout: (h, page) => h(AppShellV2, { variant: 'wide' }, () => page),
})

const props = defineProps({
    settings:    { type: Object, required: true },
    has_founder: { type: Boolean, default: false },
    channels:    { type: Array, default: () => [] },
    founding:    { type: Boolean, default: true },
    self_url:    { type: String, default: null },
})

const page = usePage()
const hasFounder = ref(props.has_founder)
const isJoin = computed(() => props.settings?.setup_mode === 'join')

// ── 1. Founder account ──────────────────────────────────────────────────────
const founderName            = ref('')
const founderEmail           = ref('')
const founderPassword        = ref('')
const founderPasswordConfirm = ref('')
const creatingFounder        = ref(false)
const founderError           = ref(null)

const canCreateFounder = computed(() =>
    !!founderName.value.trim() && !!founderEmail.value.trim()
    && founderPassword.value.length >= 8
    && founderPassword.value === founderPasswordConfirm.value)

async function createFounder() {
    if (!canCreateFounder.value || creatingFounder.value) return
    creatingFounder.value = true
    founderError.value = null
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
        // Account created + logged in. Reload this step so the node/roles/deploy
        // sections (which need an operator session) render.
        router.reload({ only: ['has_founder', 'channels', 'founding', 'self_url'] })
        hasFounder.value = true
    } catch (e) {
        founderError.value = e.message
    } finally {
        creatingFounder.value = false
    }
}

// ── 2. Node address (novice) ────────────────────────────────────────────────
// window.location.origin is the address THIS browser reached the box at — the
// best "detect" we have (the container can't see the host's LAN IP).
const detectedOrigin = typeof window !== 'undefined' ? window.location.origin : ''
const detectedIsLocalhost = /^https?:\/\/(localhost|127\.0\.0\.1)(:|$)/i.test(detectedOrigin)

// 'solo' = just me (no peer address); 'open' = others can connect (peer address).
const reach   = ref(props.self_url ? 'open' : 'solo')
const selfUrl = ref(props.self_url || (detectedIsLocalhost ? '' : detectedOrigin))
const instanceName = ref(props.settings?.instance_name && props.settings.instance_name !== 'Unnamed Instance'
    ? props.settings.instance_name : '')
const savingProfile   = ref(false)
const profileError    = ref(null)
const profileSaved    = ref(false)
const restartRequired = ref(false)

function useDetected() { selfUrl.value = detectedOrigin }

async function saveProfile() {
    if (savingProfile.value) return
    savingProfile.value = true
    profileError.value = null
    profileSaved.value = false
    try {
        const res = await csrfFetch('/api/setup/operator/profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                instance_name: instanceName.value.trim() || null,
                // 'solo' reach → no peer address (optional until you accept peers).
                self_url: reach.value === 'open' && selfUrl.value.trim() ? selfUrl.value.trim() : null,
            }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            profileError.value = data.error || data.message || 'Could not save.'
            return
        }
        profileSaved.value = true
        restartRequired.value = !!data.restart_required
        if (data.settings?.instance_name) instanceName.value = data.settings.instance_name
    } catch (e) {
        profileError.value = e.message
    } finally {
        savingProfile.value = false
    }
}

// ── 3. Deploy packages ──────────────────────────────────────────────────────
function downloadPackage(os, kind) {
    window.location.href = `/api/setup/deploy-package?os=${os}&kind=${kind}`
}

// ── 3. Operator roles (inline establish) ────────────────────────────────────
// Local, reactive copy of the channel list — seeded from props, then replaced
// wholesale from the establish response so the panel updates in place without a
// console jump. The GET seed carries {capability,label,what,established}; the
// establish response adds needs_setup — default it to false until then.
const channels = ref((props.channels || []).map((c) => ({ ...c, needs_setup: !!c.needs_setup })))
// Creating the founder account partial-reloads this step; props.channels is only
// populated once an operator exists, so re-seed the local list when it arrives
// (unless the operator has already toggled roles here, i.e. the list is non-empty).
watch(() => props.channels, (next) => {
    if (!channels.value.length && Array.isArray(next) && next.length) {
        channels.value = next.map((c) => ({ ...c, needs_setup: !!c.needs_setup }))
    }
})
const establishingAll = ref(false)
const establishingCap = ref(null)   // capability currently toggling, for per-row spinner
const rolesError      = ref(null)

const activeChannels = computed(() => channels.value.filter((c) => c.established).length)
// Channels that turned on but still need infra config before they actually work.
const needsSetup = computed(() => channels.value.filter((c) => c.established && c.needs_setup))

// broker.dns / broker.tls are configured on the broker console (/operator); every
// other channel is configured on the full operator console (/operator/roles).
const BROKER_CHANNELS = ['broker.dns', 'broker.tls']
function configHref(capability) {
    return BROKER_CHANNELS.includes(capability) ? '/operator' : '/operator/roles'
}
function configLabel(capability) {
    return BROKER_CHANNELS.includes(capability)
        ? 'Configure on the broker console'
        : 'Configure on the operator console'
}

async function establishRoles(capabilities /* array | null (= all) */) {
    if (establishingAll.value || establishingCap.value) return
    rolesError.value = null
    if (capabilities === null) establishingAll.value = true
    else establishingCap.value = capabilities[0]
    try {
        const res = await csrfFetch('/api/setup/operator/roles/establish', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            // Omit the key entirely when turning on all (the endpoint treats
            // empty/absent capabilities as "all of them").
            body: JSON.stringify(capabilities === null ? {} : { capabilities }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            rolesError.value = data.error || data.message || 'Could not turn on the roles.'
            return
        }
        // Only replace the local list from a NON-EMPTY response — an empty
        // channels array (e.g. a transient channels() read failure) must not wipe
        // the list and flip the panel back to the "account not created" empty state.
        if (Array.isArray(data.channels) && data.channels.length) {
            channels.value = data.channels.map((c) => ({ ...c, needs_setup: !!c.needs_setup }))
        }
        // Surface any per-channel establish errors returned alongside a 200.
        const failed = data.errors && Object.keys(data.errors)
        if (failed && failed.length) {
            rolesError.value = failed.map((cap) => `${cap}: ${data.errors[cap]}`).join(' · ')
        }
    } catch (e) {
        rolesError.value = e.message
    } finally {
        establishingAll.value = false
        establishingCap.value = null
    }
}

const turnOnAll     = () => establishRoles(null)
const turnOnChannel = (cap) => establishRoles([cap])

// ── 2b. The four named-role cards (design contract fixtures-operator.js) ────
// A friendly grouping over the channel substrate — labels and duties from the
// mockup, verbatim in spirit; the substrate stays the source of truth and the
// cards grant nothing the channels don't.
const NAMED_ROLES = [
    {
        key: 'record_keeper', label: 'Record Keeper', recommended: true,
        what: 'Mirror the public record and keep the world\'s geodata flowing.',
        duty: 'Keep your box on and synced; serve the shared record to peers.',
        channels: ['mirror', 'etl'],
        consent: 'Self-asserted — one click. Your own infrastructure choice.',
    },
    {
        key: 'archivist', label: 'Archivist', recommended: false,
        what: 'A full peer serving browser players the whole application.',
        duty: 'Serve the app itself — uptime and bandwidth for real people.',
        channels: ['client.serve'],
        consent: 'Governed — at founding you self-assert; later changes go through shared consent.',
    },
    {
        key: 'social_moderator', label: 'Social Moderator', recommended: false,
        what: 'Host the live rooms — chat, voice, and the surfaces they run on.',
        duty: 'Run the homeserver and media plumbing the commons ride on.',
        channels: ['matrix.homeserver', 'voice.sfu', 'client.serve'],
        consent: 'Governed — at founding you self-assert; later changes go through shared consent.',
    },
    {
        key: 'identity_broker', label: 'Identity Broker', recommended: false,
        what: 'Real names and certificates for mesh nodes — the heaviest duty.',
        duty: 'Hold a sealed DNS token; grant names and certs to peers.',
        channels: ['broker.dns', 'broker.tls', 'authority.grant', 'client.serve'],
        consent: 'Governed — the heaviest bar: co-affected peers consent to later changes.',
    },
]

const roleEstablished = (role) => {
    const on = new Set(channels.value.filter((c) => c.established).map((c) => c.capability))
    return role.channels.every((cap) => on.has(cap))
}
const chooseRole = (role) => establishRoles(role.channels)

// ── Continue ────────────────────────────────────────────────────────────────
function continueNext() {
    router.visit(isJoin.value ? '/setup/join' : '/setup/step/0')
}
</script>

<template>
    <div class="max-w-3xl mx-auto w-full px-6 py-12 space-y-8">
        <header>
            <h1 class="text-3xl font-bold text-white">Set up your node</h1>
            <p class="text-gray-400 mt-2">
                Five steps: claim your account, name the instance, pick a role, finish its
                setup, and you're set. You're the founding operator, so every role is yours
                to switch on directly.
            </p>
        </header>

        <!-- ── 0 · Claim your account ── -->
        <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xl font-semibold text-white">0 · Claim your account</h2>
                <span v-if="hasFounder" class="text-emerald-400 text-sm">✓ Claimed</span>
            </div>

            <template v-if="!hasFounder">
                <p class="text-gray-400 text-sm mb-4">
                    <strong class="text-gray-200">Path A — a fresh local account.</strong>
                    Your physical-operator credentials; the password works on this box only.
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
                <button :disabled="!canCreateFounder || creatingFounder" @click="createFounder"
                    class="mt-4 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700 disabled:text-gray-500 text-white rounded-md transition">
                    {{ creatingFounder ? 'Creating…' : 'Create operator account' }}
                </button>

                <!-- Path B — device-key linking. The built Flow B (POST /operator/link)
                     deliberately requires an operator session, so TRUE pre-account
                     linking has no backend; this card states the boundary honestly
                     rather than simulating the flow. -->
                <div class="mt-4 bg-gray-950 border border-gray-800 rounded p-4">
                    <p class="text-gray-300 text-sm font-medium mb-1">
                        Path B — link an existing mesh identity
                    </p>
                    <p class="text-gray-500 text-xs">
                        Already an operator elsewhere on the mesh? You can recognise yourself
                        across boxes by <strong>device-key possession</strong> — a key your
                        identity already trusts signs a one-time proof; no password is ever
                        replayed between boxes. On this box: claim the account above first,
                        then link the mesh identity from
                        <a href="/operator/identity" class="text-blue-400 hover:text-blue-300 underline">Identity → devices</a>
                        — only the Ed25519 public key enrols; the secret never leaves your device.
                    </p>
                </div>
            </template>
            <p v-else class="text-gray-400 text-sm">
                Account claimed. Linking a mesh identity by device key lives on
                <a href="/operator/identity" class="text-blue-400 hover:text-blue-300 underline">the Identity page</a>.
            </p>
        </section>

        <template v-if="hasFounder">
            <!-- ── 1 · Name the instance ── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xl font-semibold text-white">1 · Name the instance</h2>
                    <span v-if="profileSaved" class="text-emerald-400 text-sm">✓ Saved</span>
                </div>

                <label class="block mb-4">
                    <span class="block text-xs text-gray-400 mb-1">Node name (optional)</span>
                    <input v-model="instanceName" type="text" placeholder="e.g. Home node"
                        class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
                </label>

                <p class="text-gray-400 text-sm mb-3">Who should be able to reach this node?</p>
                <div class="space-y-2">
                    <label class="flex items-start gap-3 bg-gray-950 border rounded p-3 cursor-pointer"
                        :class="reach === 'solo' ? 'border-emerald-600' : 'border-gray-800'">
                        <input type="radio" value="solo" v-model="reach" class="mt-1" />
                        <span>
                            <span class="block text-gray-100 text-sm font-medium">Just me, for now</span>
                            <span class="block text-gray-500 text-xs">
                                Runs on this computer. No address needed — you can open it to others later
                                when you're ready to accept peers.
                            </span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 bg-gray-950 border rounded p-3 cursor-pointer"
                        :class="reach === 'open' ? 'border-emerald-600' : 'border-gray-800'">
                        <input type="radio" value="open" v-model="reach" class="mt-1" />
                        <span class="flex-1">
                            <span class="block text-gray-100 text-sm font-medium">Let other computers / people connect</span>
                            <span class="block text-gray-500 text-xs mb-2">
                                Set the address peers dial to reach this box.
                            </span>
                            <template v-if="reach === 'open'">
                                <input v-model="selfUrl" type="url" placeholder="http://192.168.1.20:8080"
                                    class="w-full bg-gray-900 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
                                <div class="flex items-center gap-2 mt-2">
                                    <button type="button" @click="useDetected"
                                        class="text-xs text-blue-400 hover:text-blue-300">
                                        Use this browser's address ({{ detectedOrigin }})
                                    </button>
                                    <span v-if="detectedIsLocalhost" class="text-xs text-amber-400">
                                        — localhost only works on this computer; use your LAN/public address for real peers.
                                    </span>
                                </div>
                            </template>
                        </span>
                    </label>
                </div>

                <div v-if="profileError" class="mt-3 text-sm text-red-400">{{ profileError }}</div>
                <div v-if="restartRequired" class="mt-3 text-xs text-amber-400 bg-amber-900/20 border border-amber-800/50 rounded p-2">
                    The address changed. Re-run the start command
                    (<code class="text-amber-300">docker compose up -d</code>) so the containers pick it up before peers join.
                </div>

                <!-- type="button" is explicit: a <button> with no type defaults
                     to submit, which would navigate (full reload) the moment
                     this markup ever sits inside a form. Every other control
                     here declares it; this one was the lone omission. -->
                <button type="button" :disabled="savingProfile" @click="saveProfile"
                    class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 text-white rounded-md transition">
                    {{ savingProfile ? 'Saving…' : 'Save' }}
                </button>
            </section>

            <!-- ── 2 · Pick a role ── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <h2 class="text-xl font-semibold text-white mb-3">2 · Pick a role</h2>
                <p class="text-gray-400 text-sm mb-4">
                    A role is a friendly grouping over the capability channels — no new power.
                    Roles are infrastructure duties, not citizen privilege (they buy no vote or
                    seat). As the founding operator you self-assert directly; once a government
                    seats, governed roles return to shared consent for later changes.
                </p>
                <div class="grid sm:grid-cols-2 gap-4 mb-2">
                    <div v-for="role in NAMED_ROLES" :key="role.key"
                        class="bg-gray-950 border rounded p-4 flex flex-col gap-2"
                        :class="roleEstablished(role) ? 'border-emerald-600' : 'border-gray-800'">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-100 text-sm font-semibold">{{ role.label }}</span>
                            <span v-if="roleEstablished(role)" class="text-emerald-400 text-xs">✓ Established</span>
                            <span v-else-if="role.recommended" class="text-amber-300 text-xs">Recommended</span>
                        </div>
                        <p class="text-gray-400 text-xs">{{ role.what }}</p>
                        <p class="text-gray-500 text-xs"><strong class="text-gray-400">Your duty:</strong> {{ role.duty }}</p>
                        <p class="text-gray-500 text-xs">
                            <code v-for="cap in role.channels" :key="cap" class="text-gray-300 mr-1">{{ cap }}</code>
                        </p>
                        <p class="text-gray-500 text-xs italic">{{ role.consent }}</p>
                        <button v-if="!roleEstablished(role)"
                            :disabled="establishingAll || !!establishingCap || !channels.length"
                            @click="chooseRole(role)"
                            class="mt-auto px-3 py-1.5 bg-gray-800 hover:bg-gray-700 disabled:bg-gray-800/50 disabled:text-gray-600 text-gray-100 text-xs rounded transition">
                            Choose {{ role.label }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- ── 3 · Role-specific setup (the channel substrate) ── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xl font-semibold text-white">3 · Role-specific setup</h2>
                    <span class="text-gray-500 text-xs">{{ activeChannels }} / {{ channels.length }} channels on</span>
                </div>
                <p class="text-gray-400 text-sm mb-4">
                    The channels beneath the role cards — turn any on individually, and finish
                    the infrastructure config where a channel needs it.
                </p>

                <div v-if="rolesError" class="mb-3 text-sm text-red-400 bg-red-900/20 border border-red-800/50 rounded p-2">
                    {{ rolesError }}
                </div>

                <button :disabled="establishingAll || !!establishingCap || activeChannels === channels.length"
                    @click="turnOnAll"
                    class="mb-4 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700 disabled:text-gray-500 text-white rounded-md transition">
                    {{ establishingAll ? 'Turning on…'
                        : activeChannels === channels.length && channels.length ? '✓ All roles on' : 'Turn on all roles' }}
                </button>

                <ul v-if="channels.length" class="space-y-2 mb-4">
                    <li v-for="c in channels" :key="c.capability"
                        class="flex items-center gap-3 bg-gray-950 border border-gray-800 rounded px-3 py-2">
                        <span :class="c.established ? 'text-emerald-400' : 'text-gray-600'">●</span>
                        <span class="flex-1 min-w-0">
                            <code class="text-gray-300 text-xs">{{ c.capability }}</code>
                            <span class="text-gray-500 text-xs"> — {{ c.label }}</span>
                        </span>
                        <span v-if="c.established" class="text-emerald-400 text-xs whitespace-nowrap">On</span>
                        <button v-else :disabled="establishingAll || !!establishingCap"
                            @click="turnOnChannel(c.capability)"
                            class="px-3 py-1 bg-gray-800 hover:bg-gray-700 disabled:bg-gray-800/50 disabled:text-gray-600 text-gray-100 text-xs rounded whitespace-nowrap transition">
                            {{ establishingCap === c.capability ? 'Turning on…' : 'Turn on' }}
                        </button>
                    </li>
                </ul>
                <p v-else class="text-gray-500 text-sm mb-4">
                    Roles become available once the operator account above is created.
                </p>

                <!-- Channels that are on but still need infra config to actually work. -->
                <div v-if="needsSetup.length"
                    class="mb-4 text-xs bg-amber-900/20 border border-amber-800/50 rounded p-3 space-y-2">
                    <p class="text-amber-300 font-medium">Some roles still need setup before they work:</p>
                    <ul class="space-y-1.5">
                        <li v-for="c in needsSetup" :key="c.capability" class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <code class="text-amber-200">{{ c.capability }}</code>
                            <span class="text-amber-400/80">— {{ c.what || 'needs configuration' }}</span>
                            <a :href="configHref(c.capability)"
                                class="text-blue-400 hover:text-blue-300 underline whitespace-nowrap">
                                {{ configLabel(c.capability) }} →
                            </a>
                        </li>
                    </ul>
                    <p class="text-amber-400/70">
                        Infrastructure config comes next — you can turn a role on now and finish its setup there.
                    </p>
                </div>

                <a href="/operator/roles"
                    class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-300">
                    Prefer the full console? Open the operator console →
                </a>
            </section>

            <!-- ── 4 · You're set — share + continue ── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-3">4 · You're set — share this deployment</h2>
                <p class="text-gray-400 text-sm mb-4">
                    Hand a colleague a one-file start script. Solo = they found their own world; Join = they
                    mirror this one (needs your node address set above).
                </p>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="bg-gray-950 border border-gray-800 rounded p-4">
                        <div class="text-emerald-400 text-xs font-semibold uppercase mb-2">Solo</div>
                        <div class="flex flex-wrap gap-2">
                            <button @click="downloadPackage('windows','solo')" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-100 text-xs rounded">Windows</button>
                            <button @click="downloadPackage('unix','solo')" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-100 text-xs rounded">macOS / Linux</button>
                        </div>
                    </div>
                    <div class="bg-gray-950 border border-gray-800 rounded p-4">
                        <div class="text-sky-400 text-xs font-semibold uppercase mb-2">Join</div>
                        <div class="flex flex-wrap gap-2">
                            <button @click="downloadPackage('windows','join')" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-100 text-xs rounded">Windows</button>
                            <button @click="downloadPackage('unix','join')" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-100 text-xs rounded">macOS / Linux</button>
                        </div>
                        <p v-if="reach !== 'open' || !selfUrl.trim()" class="text-xs text-amber-400 mt-2">
                            Set a reachable node address above first, or the join script won't know where to dial.
                        </p>
                    </div>
                </div>
            </section>

            <!-- ── Continue ── -->
            <section class="flex justify-end">
                <button @click="continueNext"
                    class="px-6 py-3 bg-emerald-600 hover:bg-emerald-500 text-white rounded-md font-semibold transition">
                    {{ isJoin ? 'Continue to join →' : 'Continue to cosmic address →' }}
                </button>
            </section>
        </template>
    </div>
</template>
