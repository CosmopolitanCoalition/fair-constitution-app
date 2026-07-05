<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import ExportBackupPanel from '@/Components/Setup/ExportBackupPanel.vue'
import { csrfFetch } from '@/lib/csrf'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

const props = defineProps({
    step:     { type: Number, required: true },
    settings: { type: Object, required: true },
    summary:  { type: Object, required: true },
})

const finishing = ref(false)
const finished  = ref(false)
const error     = ref('')
const result    = ref(null)

async function finishSetup() {
    finishing.value = true
    error.value = ''
    try {
        const res = await csrfFetch('/api/setup/wizard/step4/complete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({}),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            error.value = data.error || `Could not finish setup (HTTP ${res.status}).`
            return
        }
        result.value   = data
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
            <SetupStepper :current="4" :completed="settings.setup_step_completed" />

            <header class="mt-8 mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    Confirm &amp; Seat Institutions
                </h1>
                <p class="text-gray-300 leading-relaxed">
                    Review the shape of your instance, then seat the executive and judicial
                    institutions for every jurisdiction with a legislature. They start
                    <strong>forming</strong> — actual members get elected once the elections engine
                    ships in Phase 2 of the master roadmap.
                </p>
            </header>

            <!-- Summary cards -->
            <section v-if="!finished" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
                    <div class="text-gray-400 text-xs uppercase tracking-wide">Legislatures formed</div>
                    <div class="text-white text-3xl font-semibold mt-2">
                        {{ summary.legislatures.toLocaleString() }}
                    </div>
                    <div class="text-gray-500 text-xs mt-2 italic">
                        One per jurisdiction with direct children, sized by cube-root law.
                    </div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
                    <div class="text-gray-400 text-xs uppercase tracking-wide">Districts drawn</div>
                    <div class="text-white text-3xl font-semibold mt-2">
                        {{ summary.districts.toLocaleString() }}
                    </div>
                    <div class="text-gray-500 text-xs mt-2 italic">
                        Per the maps you built or auto-composited in Step 3.
                    </div>
                </div>
            </section>

            <!-- The data-quality review section lives at the END of Step 2,
                 BEFORE apportionment fires (Continue → activateStep1). By
                 the time the operator reaches Step 4, districts are already
                 built on top of whatever populations existed — too late
                 for population/boundary review. Step 4 stays focused on
                 institution seating. -->

            <section v-if="!finished" class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-6">
                <h2 class="text-white font-semibold mb-3">What happens when you click Finish</h2>
                <ul class="text-sm text-gray-300 space-y-2 pl-4 list-disc">
                    <li>
                        For every jurisdiction with a legislature we'll insert one <code>executives</code>
                        row (default type: <em>committee</em>) and one <code>judiciaries</code> row
                        (default type: <em>appointed</em>, min 5 judges, 10-year terms).
                    </li>
                    <li>
                        No members or seats are populated — those land via the elections engine.
                        Status stays <code>forming</code> until Phase 2.
                    </li>
                    <li>
                        We'll record <code>setup_completed_at</code> on the instance settings.
                        From this point <code>/setup</code> redirects home.
                    </li>
                </ul>
                <div
                    v-if="summary.existing_executives > 0 || summary.existing_judiciaries > 0"
                    class="mt-4 text-xs text-gray-500 italic border-t border-gray-800 pt-3"
                >
                    Existing institution rows detected
                    ({{ summary.existing_executives }} executives,
                    {{ summary.existing_judiciaries }} judiciaries) — they'll be left alone.
                </div>
            </section>

            <!-- Finished state -->
            <section
                v-if="finished"
                class="bg-emerald-900/20 border border-emerald-700/50 rounded-lg p-8 mb-6 text-center"
            >
                <div class="text-5xl mb-4">🎉</div>
                <h2 class="text-2xl font-bold text-emerald-200 mb-2">Ready Player One</h2>
                <p class="text-emerald-100/80 max-w-xl mx-auto leading-relaxed">
                    Your instance is set up. Legislatures are sized, districts are drawn, and the
                    executive + judicial institutions are scaffolded and waiting for their first
                    elections. Welcome to your fair constitution.
                </p>
                <div v-if="result" class="text-xs text-emerald-300/70 mt-6 grid grid-cols-2 gap-3 max-w-sm mx-auto">
                    <div class="text-right">Executives seeded:</div>
                    <div class="text-left font-mono">{{ result.stubs?.executives_created ?? 0 }}</div>
                    <div class="text-right">Judiciaries seeded:</div>
                    <div class="text-left font-mono">{{ result.stubs?.judiciaries_created ?? 0 }}</div>
                </div>
            </section>

            <div v-if="error" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">
                {{ error }}
            </div>

            <!-- Step 4 is the canonical place to build a portable snapshot of
                 this instance: by the time the operator arrives here the
                 FK-downstream graph (cosmic_addresses → jurisdictions →
                 legislatures → districts → constitutional_settings …) is
                 fully populated, so an export captures the entire setup
                 state in one tarball. The same panel will reappear in
                 future admin sections once those ship. -->
            <ExportBackupPanel title="Export this instance (backup / sync)" />

            <div class="flex justify-between pt-4 border-t border-gray-800 mt-4">
                <a
                    v-if="!finished"
                    href="/setup/step/3"
                    class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2"
                >← Back</a>
                <span v-else></span>

                <button
                    v-if="!finished"
                    type="button"
                    :disabled="finishing"
                    @click="finishSetup"
                    class="bg-emerald-600 hover:bg-emerald-500 disabled:bg-gray-700 text-white px-5 py-2 rounded-md font-semibold transition-colors"
                >
                    {{ finishing ? 'Seating institutions…' : 'Finish Setup →' }}
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
