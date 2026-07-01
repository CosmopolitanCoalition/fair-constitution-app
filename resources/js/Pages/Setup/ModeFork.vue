<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { csrfHeaders } from '@/lib/csrf'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

defineProps({
    settings: { type: Object, required: true },
})

const submitting = ref(false)
const submitError = ref(null)

async function choose(mode) {
    if (submitting.value) return
    submitting.value = true
    submitError.value = null
    try {
        const res = await fetch('/api/setup/mode', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify({ setup_mode: mode }),
        })
        const data = await res.json()
        if (!res.ok) {
            submitError.value = data.error || data.message || 'Could not set the mode.'
            return
        }
        router.visit(data.next || '/setup')
    } catch (e) {
        submitError.value = e.message || 'Network error'
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-12 w-full">
        <h1 class="text-2xl font-semibold text-white mb-2">How should this node join the game?</h1>
        <p class="text-gray-400 mb-8">
            Your operator account is set up. Every node in a mesh plays the same game — so the only
            question is whether this node <em>starts</em> a world or <em>joins</em> one that already exists.
        </p>

        <div v-if="submitError" class="mb-6 text-sm text-red-400">{{ submitError }}</div>

        <div class="grid md:grid-cols-2 gap-5">
            <button type="button" :disabled="submitting" @click="choose('solo')"
                class="text-left bg-gray-900 border border-gray-800 hover:border-emerald-600 rounded-xl p-6
                       disabled:opacity-50 transition-colors">
                <div class="text-emerald-400 text-xs font-semibold tracking-wide uppercase mb-2">Start a new world</div>
                <h2 class="text-white text-lg font-semibold mb-2">Solo</h2>
                <p class="text-gray-400 text-sm">
                    Build the game here — author the constitution, load the map, draw districts, seat the
                    institutions. <strong>You become the canonical game</strong>, and other nodes federate to you.
                    Recommended for the first node in a federation.
                </p>
            </button>

            <button type="button" :disabled="submitting" @click="choose('join')"
                class="text-left bg-gray-900 border border-gray-800 hover:border-sky-600 rounded-xl p-6
                       disabled:opacity-50 transition-colors">
                <div class="text-sky-400 text-xs font-semibold tracking-wide uppercase mb-2">Join an existing mesh</div>
                <h2 class="text-white text-lg font-semibold mb-2">Join</h2>
                <p class="text-gray-400 text-sm">
                    Connect to a host already in the federation. <strong>Everything syncs in</strong> — the map
                    foundation, the constitution, and every institution — and this node becomes a read-only mirror.
                    No institution-building; you're playing the same game as the rest of the mesh.
                </p>
            </button>
        </div>

        <p class="text-gray-600 text-xs mt-8">
            This choice is one-way for the life of the instance. Tearing down and starting over is
            <code class="text-gray-400">docker compose down -v</code>.
        </p>
    </div>
</template>
