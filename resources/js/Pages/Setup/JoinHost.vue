<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import SyncProgress from '@/Components/Federation/SyncProgress.vue'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

const props = defineProps({
    settings: { type: Object, required: true },
    server_id: { type: String, default: null },
})

const hostUrl = ref('')
const joinKey = ref('')
const submitting = ref(false)
const error = ref(null)
const state = ref(null) // 'ready' | 'syncing' | 'pending_host_approval'
const sync = ref(null)   // <SyncProgress> ref
const finalizing = ref(false)

// Once a prior attempt has pinned this box as a mirror, RESUME needs no host URL — the server resumes
// the already-pinned host (SetupController isMirror() branch). So after a page reload (hostUrl resets to
// blank) the Resume button must stay live; guarding it on hostUrl would strand the operator on a failed
// drain with a dead button.
const isMirror = computed(() => !!props.settings?.is_mirror)

// ── Auto-discovery ────────────────────────────────────────────────────────────
const discovering = ref(false)
const discoverError = ref(null)
const federations = ref([])
const discovered = ref(false)
const scanLan = ref(false)
const lanCidr = ref('')

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

async function discover() {
    if (discovering.value) return
    discovering.value = true
    discoverError.value = null
    try {
        const res = await fetch('/api/setup/discover', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ lan: scanLan.value, cidr: scanLan.value ? lanCidr.value.trim() : null }),
        })
        const data = await res.json()
        if (!res.ok) {
            discoverError.value = data.error || data.message || 'Discovery failed.'
            return
        }
        federations.value = data.federations || []
        // The front door still returns results even when the LAN range is rejected — surface the range
        // error without hiding the federations that were found.
        discoverError.value = data.lan_error || null
        discovered.value = true
    } catch (e) {
        discoverError.value = e.message || 'Network error'
    } finally {
        discovering.value = false
    }
}

function choose(fed) {
    hostUrl.value = fed.url
}

async function submit() {
    // A fresh join needs a host URL; a resume (already a mirror) does not — the server picks up the
    // pinned host. Never early-return on a blank URL when we are already mirroring.
    if (submitting.value || (!isMirror.value && !hostUrl.value.trim())) return
    submitting.value = true
    error.value = null
    try {
        const res = await fetch('/api/setup/join', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                host_url: hostUrl.value.trim(),
                join_key: joinKey.value.trim() || null,
            }),
        })
        const data = await res.json()
        if (!res.ok) {
            error.value = data.error || data.message || 'Join failed.'
            return
        }
        state.value = data.state
        if (data.state === 'ready') {
            router.visit(data.next || '/')
        } else if (data.state === 'syncing') {
            // The seed + drain now runs in the background. Start polling the live progress panel
            // immediately; it auto-finalizes (below) when the corpus catches up.
            sync.value?.start()
        }
    } catch (e) {
        error.value = e.message || 'Network error'
    } finally {
        submitting.value = false
    }
}

// Fired by <SyncProgress> when the background drain catches up (membership LIVE). Re-POST so the
// server stamps setup complete and hands us the next URL — no host_url needed (the server resumes the
// already-pinned mirror).
async function finalize() {
    if (finalizing.value) return
    finalizing.value = true
    try {
        const res = await fetch('/api/setup/join', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ host_url: hostUrl.value.trim() || null, join_key: joinKey.value.trim() || null }),
        })
        const data = await res.json()
        if (res.ok && data.state === 'ready') {
            router.visit(data.next || '/')
        }
    } catch (e) {
        // Leave the panel up — the operator can re-submit to finalize.
    } finally {
        finalizing.value = false
    }
}
</script>

<template>
    <div class="max-w-2xl mx-auto px-6 py-12 w-full">
        <h1 class="text-2xl font-semibold text-white mb-2">Join an existing mesh</h1>
        <p class="text-gray-400 mb-8">
            Connect this node to a federation already in play. It syncs the whole game — map foundation,
            constitution, and institutions — and becomes a read-only mirror. There's no institution-building:
            you're playing the same game as the rest of the mesh.
        </p>

        <!-- Discover: find a federation with no address up front. -->
        <div class="mb-8 bg-gray-900 border border-gray-800 rounded-lg p-5">
            <div class="flex items-center justify-between mb-1">
                <h2 class="text-base font-semibold text-white">Find a federation</h2>
                <button type="button" :disabled="discovering" @click="discover"
                    class="bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700 text-white px-4 py-1.5 rounded text-sm font-semibold">
                    {{ discovering ? 'Searching…' : 'Discover' }}
                </button>
            </div>
            <p class="text-xs text-gray-500 mb-3">
                Checks the public front door, and — if you opt in — scans your own local network. No address needed.
            </p>

            <label class="flex items-center gap-2 text-sm text-gray-300 mb-2">
                <input type="checkbox" v-model="scanLan" class="rounded border-gray-700 bg-gray-950" />
                Also scan my local network
            </label>
            <label v-if="scanLan" class="block mb-3">
                <span class="block text-xs text-gray-500 mb-1">Your LAN range (CIDR)</span>
                <input v-model="lanCidr" type="text" placeholder="192.168.1.0/24"
                    class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
            </label>

            <div v-if="discoverError" class="text-sm text-red-400 mb-2">{{ discoverError }}</div>

            <ul v-if="federations.length" class="space-y-2">
                <li v-for="fed in federations" :key="(fed.server_id || fed.url)"
                    class="flex items-center justify-between gap-3 bg-gray-950 border border-gray-800 rounded px-3 py-2">
                    <div class="min-w-0">
                        <div class="text-sm text-gray-100 truncate">{{ fed.name }}</div>
                        <div class="text-xs text-gray-500 truncate">{{ fed.url }}</div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-[10px] uppercase tracking-wide rounded px-1.5 py-0.5"
                            :class="fed.source === 'lan' ? 'bg-sky-900 text-sky-300' : 'bg-gray-800 text-gray-400'">
                            {{ fed.source === 'lan' ? 'LAN' : 'front door' }}
                        </span>
                        <span v-if="!fed.accepting_joins" class="text-[10px] text-amber-400">not open</span>
                        <button type="button" @click="choose(fed)"
                            class="bg-sky-700 hover:bg-sky-600 text-white px-3 py-1 rounded text-xs font-semibold">
                            Use
                        </button>
                    </div>
                </li>
            </ul>
            <p v-else-if="discovered && !discovering" class="text-sm text-gray-500">
                No federations found. Enter a host URL manually below, or check your network range and try again.
            </p>
        </div>

        <div v-if="server_id" class="mb-6 bg-gray-900 border border-gray-800 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">This node's mesh id (give it to the host)</div>
            <code class="text-sky-300 text-sm break-all">{{ server_id }}</code>
        </div>

        <label class="block mb-4">
            <span class="block text-sm text-gray-300 mb-1">Host URL</span>
            <input v-model="hostUrl" type="url" placeholder="http://192.168.1.202:8081"
                class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
        </label>

        <label class="block mb-6">
            <span class="block text-sm text-gray-300 mb-1">
                Join key
                <span class="text-gray-600">(optional — leave blank to request the host operator's approval)</span>
            </span>
            <input v-model="joinKey" type="text" placeholder="handle.secret"
                class="w-full bg-gray-950 border border-gray-800 rounded px-3 py-2 text-gray-100 text-sm" />
        </label>

        <div v-if="error" class="mb-4 text-sm text-red-400">{{ error }}</div>

        <div v-if="state === 'pending_host_approval'" class="mb-4 text-sm text-amber-300">
            Request sent — the host operator must approve it. Re-submit once they have, and the sync begins.
        </div>
        <div v-else-if="state === 'syncing'" class="mb-4 text-sm text-sky-300">
            Connected — pulling the corpus in the background. Live progress is shown below; this finishes on
            its own (and resumes if interrupted). You can leave this page.
        </div>

        <!-- Live seed + drain progress (per-table bars, %/ETA) — the same panel the federation console
             uses. It self-hides when idle, shows progress while the background job runs, and auto-finalizes
             the join the moment the corpus catches up. -->
        <SyncProgress ref="sync" class="mb-4" @done="finalize" />

        <button type="button" :disabled="submitting || (!isMirror && !hostUrl.trim())" @click="submit"
            class="bg-sky-600 hover:bg-sky-500 disabled:bg-gray-700 text-white px-5 py-2 rounded text-sm font-semibold">
            {{ submitting ? 'Joining…' : ((state === 'syncing' || isMirror) ? 'Resume the sync' : 'Join the mesh') }}
        </button>
        <p v-if="isMirror && state !== 'ready'" class="mt-2 text-xs text-gray-500">
            Already connected to a host — this resumes the sync where it left off (no host URL needed).
        </p>
    </div>
</template>
