<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import { csrfFetch } from '@/lib/csrf'

// STEP 5 — SIMULATE. Opens only on a sandbox world that chose "simulate at
// scale" at map acceptance (the wizard ladder). The simulation page itself is
// Wave 7's work: full planet, all stages, halt / resume / rollback, then lock.
// Until it lands, this step records the choice and continues.
defineOptions({
    layout: (h, page) => h(AppShellV2, { variant: 'wide' }, () => page),
})

const props = defineProps({
    step:     { type: Number, required: true },
    settings: { type: Object, required: true },
})

const busy  = ref(false)
const error = ref('')

async function skipForNow() {
    busy.value = true
    error.value = ''
    try {
        const res = await csrfFetch('/api/setup/wizard/step5/complete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({}),
        })
        const json = await res.json().catch(() => ({}))
        if (!res.ok) { error.value = json.error || `Refused (HTTP ${res.status}).`; return }
        router.visit(json.next || '/setup')
    } catch (e) {
        error.value = String(e)
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-8 w-full">
        <SetupStepper :current="5" :completed="settings.setup_step_completed" :steps="settings.ladder" />

        <header class="mt-8 mb-6">
            <h1 class="text-3xl font-bold text-white mb-2">Simulate</h1>
            <p class="text-gray-300 leading-relaxed">
                Step 4 sculpted the world. Step 5 simulates what people do in it: residents, candidacies,
                elections counted by the real engine, chambers, acts. This page arrives in the next build
                wave with the planet-scale simulation run and its halt, resume and rollback controls. The
                simulation console at <code>/simworld</code> drives the engine today.
            </p>
        </header>

        <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6 text-sm text-gray-300 space-y-2">
            <div>Choice recorded at map acceptance: <span class="text-white">simulate at scale</span> on a <span class="text-white">{{ settings.game_mode }}</span> world.</div>
            <div>Continue closes this step for now. The simulation can be started from its console at any time.</div>
        </section>

        <div v-if="error" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">{{ error }}</div>

        <div class="flex justify-between pt-4 border-t border-gray-800 mt-4">
            <a href="/setup/step/4" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">← Back</a>
            <button
                type="button"
                :disabled="busy"
                @click="skipForNow"
                class="bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700 text-white px-5 py-2 rounded-md font-semibold transition-colors"
            >
                {{ busy ? 'Continuing…' : 'Continue →' }}
            </button>
        </div>
    </div>
</template>
