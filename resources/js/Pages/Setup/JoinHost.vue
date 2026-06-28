<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

defineProps({
    settings: { type: Object, required: true },
    server_id: { type: String, default: null },
})

const hostUrl = ref('')
const joinKey = ref('')
const submitting = ref(false)
const error = ref(null)
const state = ref(null) // 'ready' | 'syncing' | 'pending_host_approval'

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
    if (submitting.value || !hostUrl.value.trim()) return
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
        }
    } catch (e) {
        error.value = e.message || 'Network error'
    } finally {
        submitting.value = false
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
            Connected — still pulling the corpus. Re-submit to resume; it picks up where it left off.
        </div>

        <button type="button" :disabled="submitting || !hostUrl.trim()" @click="submit"
            class="bg-sky-600 hover:bg-sky-500 disabled:bg-gray-700 text-white px-5 py-2 rounded text-sm font-semibold">
            {{ submitting ? 'Joining…' : 'Join the mesh' }}
        </button>
    </div>
</template>
