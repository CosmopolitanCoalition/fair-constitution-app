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

async function submit() {
    if (submitting.value || !hostUrl.value.trim()) return
    submitting.value = true
    error.value = null
    try {
        const res = await fetch('/api/setup/join', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
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
            Point this node at a host already in the federation. It syncs the whole game — map foundation,
            constitution, and institutions — and becomes a read-only mirror. There's no institution-building:
            you're playing the same game as the rest of the mesh.
        </p>

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
