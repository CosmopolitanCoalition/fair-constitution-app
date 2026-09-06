<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import ExportBackupPanel from '@/Components/Setup/ExportBackupPanel.vue'
import { csrfFetch } from '@/lib/csrf'

// STEP 6 — CONFIRM AND CLOSE (Wave 6). Finishing records the confirmation and
// stamps setup_completed_at; from then on /setup redirects home. Institutions
// were Step 4's (the engine); nothing is dispatched from here.
defineOptions({
    layout: (h, page) => h(AppShellV2, { variant: 'wide' }, () => page),
})

const props = defineProps({
    step:     { type: Number, required: true },
    settings: { type: Object, required: true },
    summary:  { type: Object, required: true },
})

const finishing = ref(false)
const finished  = ref(false)
const error     = ref('')

async function finishSetup() {
    finishing.value = true
    error.value = ''
    try {
        const res = await csrfFetch('/api/setup/wizard/step6/complete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({}),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            error.value = data.error || `Could not finish setup (HTTP ${res.status}).`
            return
        }
        finished.value = true
    } catch (e) {
        error.value = String(e)
    } finally {
        finishing.value = false
    }
}

function goHome() {
    router.visit('/')
}
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-8 w-full">
        <SetupStepper :current="6" :completed="settings.setup_step_completed" :steps="settings.ladder" />

        <header class="mt-8 mb-6">
            <h1 class="text-3xl font-bold text-white mb-2">Confirm &amp; Close</h1>
            <p class="text-gray-300 leading-relaxed">
                Review the shape of the instance, then close setup. From this point the wizard is
                finished: the constants and the game mode lock, and the world opens.
            </p>
        </header>

        <section v-if="!finished" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Legislatures</div>
                <div class="text-white text-3xl font-semibold mt-2">{{ summary.legislatures.toLocaleString() }}</div>
                <div class="text-gray-500 text-xs mt-2">One per jurisdiction, sized by the cube-root law.</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Districts</div>
                <div class="text-white text-3xl font-semibold mt-2">{{ summary.districts.toLocaleString() }}</div>
                <div class="text-gray-500 text-xs mt-2">Drawn in Step 3.</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Executives</div>
                <div class="text-white text-3xl font-semibold mt-2">{{ summary.existing_executives.toLocaleString() }}</div>
                <div class="text-gray-500 text-xs mt-2">Provisioned in Step 4, or by activation.</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
                <div class="text-gray-400 text-xs uppercase tracking-wide">Courts</div>
                <div class="text-white text-3xl font-semibold mt-2">{{ summary.existing_judiciaries.toLocaleString() }}</div>
                <div class="text-gray-500 text-xs mt-2">Benches sized by the bench law.</div>
            </div>
        </section>

        <section v-if="!finished" class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
            <h2 class="text-white font-semibold mb-3">What happens when you click Finish</h2>
            <ul class="text-sm text-gray-300 space-y-2 pl-4 list-disc">
                <li>The confirmation and the data-quality snapshot are recorded on the instance settings.</li>
                <li><code>setup_completed_at</code> is stamped. <code>/setup</code> redirects home from then on.</li>
                <li>Nothing else runs. Institutions were Step 4's; the simulation is Step 5's.</li>
            </ul>
        </section>

        <section v-if="finished" class="bg-emerald-900/20 border border-emerald-700/50 rounded-lg p-8 mb-6 text-center">
            <div class="text-5xl mb-4">🎉</div>
            <h2 class="text-2xl font-bold text-emerald-200 mb-2">Setup closed</h2>
            <p class="text-emerald-100/80 max-w-xl mx-auto leading-relaxed">
                The instance is set up. Legislatures are sized, districts are drawn, institutions stand. Welcome to your fair constitution.
            </p>
        </section>

        <div v-if="error" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">{{ error }}</div>

        <ExportBackupPanel title="Export this instance (backup / sync)" />

        <div class="flex justify-between pt-4 border-t border-gray-800 mt-4">
            <a v-if="!finished" href="/setup" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">← Back</a>
            <span v-else></span>

            <button
                v-if="!finished"
                type="button"
                :disabled="finishing"
                @click="finishSetup"
                class="bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700 text-white px-5 py-2 rounded-md font-semibold transition-colors"
            >
                {{ finishing ? 'Closing…' : 'Finish Setup →' }}
            </button>
            <button
                v-else
                type="button"
                @click="goHome"
                class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2 rounded-md font-semibold transition-colors"
            >
                Enter Instance →
            </button>
        </div>
    </div>
</template>
