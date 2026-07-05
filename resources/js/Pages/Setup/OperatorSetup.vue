<script setup>
/**
 * Operator-setup step — the START-path "operator console at bootstrap"
 * (operator ruling 2026-07-05). Comes AFTER the JOIN-or-START fork, BEFORE the
 * cosmic address. Three parts:
 *   1. Create the operator/founder account (the physical credentials that run the box).
 *   2. Node address — novice-friendly: "just me for now" needs no peer address;
 *      opening the node to others uses the address the browser is already on.
 *   3. Operator roles — as the founding operator you self-assert them all; a
 *      one-click baseline plus a link to the full console (which now self-asserts
 *      in founding, no request/qualify dance).
 * Plus the shareable deploy packages, and Continue (→ cosmic for solo, → join).
 */
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { csrfFetch } from '@/lib/csrf'

defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
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

// ── Continue ────────────────────────────────────────────────────────────────
function continueNext() {
    router.visit(isJoin.value ? '/setup/join' : '/setup/step/0')
}

const activeChannels = computed(() => props.channels.filter((c) => c.established).length)
</script>

<template>
    <div class="max-w-3xl mx-auto w-full px-6 py-12 space-y-8">
        <header>
            <h1 class="text-3xl font-bold text-white">Operator setup</h1>
            <p class="text-gray-400 mt-2">
                Create the account that runs this node, give the box an address, and turn on your operator
                roles. You're the founding operator, so every role is yours to switch on directly.
            </p>
        </header>

        <!-- ── 1 · Operator account ── -->
        <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xl font-semibold text-white">1 · Operator account</h2>
                <span v-if="hasFounder" class="text-emerald-400 text-sm">✓ Created</span>
            </div>

            <template v-if="!hasFounder">
                <p class="text-gray-400 text-sm mb-4">
                    Your physical-operator credentials — the account that runs and governs this node.
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
            </template>
            <p v-else class="text-gray-400 text-sm">Operator account is set.</p>
        </section>

        <template v-if="hasFounder">
            <!-- ── 2 · Node address ── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xl font-semibold text-white">2 · This node's address</h2>
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

                <button :disabled="savingProfile" @click="saveProfile"
                    class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 text-white rounded-md transition">
                    {{ savingProfile ? 'Saving…' : 'Save' }}
                </button>
            </section>

            <!-- ── 3 · Operator roles ── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xl font-semibold text-white">3 · Operator roles</h2>
                    <span class="text-gray-500 text-xs">{{ activeChannels }} / {{ channels.length }} on</span>
                </div>
                <p class="text-gray-400 text-sm mb-3">
                    Running a node carries operator <strong>roles</strong> on the mesh — infrastructure duties,
                    not citizen privilege (they buy no vote or seat). As the founding operator you self-assert
                    them directly; once a government seats, governed roles return to shared consent for later changes.
                </p>
                <ul v-if="channels.length" class="text-xs text-gray-400 space-y-1 mb-4">
                    <li v-for="c in channels" :key="c.capability" class="flex items-center gap-2">
                        <span :class="c.established ? 'text-emerald-400' : 'text-gray-600'">●</span>
                        <code class="text-gray-300">{{ c.capability }}</code>
                        <span class="text-gray-600">— {{ c.label }}</span>
                    </li>
                </ul>
                <a href="/operator/roles"
                    class="inline-flex items-center gap-1 text-sm text-blue-400 hover:text-blue-300">
                    Open the operator console to turn roles on →
                </a>
            </section>

            <!-- ── Share this deployment ── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-3">Share this deployment</h2>
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
