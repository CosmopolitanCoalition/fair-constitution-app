<script setup>
import { onMounted, ref } from 'vue'
import AppShell from '@/Layouts/AppShell.vue'
import SetupStepper from '@/Components/SetupStepper.vue'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

const props = defineProps({
    step: { type: Number, required: true },
    settings: { type: Object, required: true },
    root_jurisdiction: { type: Object, default: null },
    root_legislature_id: { type: String, default: null },
})

// Gate on the legislature existing, but address it by the root jurisdiction's
// slug (canonical, parity with the jurisdiction viewer). Fall back to the UUID
// if the slug somehow isn't present — the mapper route dual-accepts both.
const mapperHref = props.root_legislature_id
    ? `/legislatures/${props.root_jurisdiction?.slug ?? props.root_legislature_id}?setup=1`
    : null

const summary = ref(null)
const summaryError = ref('')

async function loadSummary() {
    try {
        const res = await fetch('/api/setup/wizard/step3/summary', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) {
            summaryError.value = `Could not load apportionment summary (HTTP ${res.status}).`
            return
        }
        summary.value = await res.json()
    } catch (e) {
        summaryError.value = String(e)
    }
}

onMounted(loadSummary)
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-8 w-full">
            <SetupStepper :current="3" :completed="settings.setup_step_completed" />

            <header class="mt-8 mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    Build Your Districts
                </h1>
                <p class="text-gray-300 leading-relaxed">
                    This is where your instance gets its first shape. Every legislature needs a map —
                    a partition of its jurisdiction into districts sized within the constitutional
                    min/max seat range. Setup builds the <strong>first</strong> legislature — the root
                    jurisdiction's. Additional legislatures activate automatically as jurisdictions
                    reach critical population (CLK-06).
                </p>
            </header>

            <!-- Apportionment summary -->
            <section
                v-if="summary"
                class="bg-emerald-900/20 border border-emerald-800/50 rounded-lg p-5 mb-6"
            >
                <div class="flex items-baseline gap-2 mb-3">
                    <span class="text-emerald-400 text-lg">✓</span>
                    <h2 class="text-emerald-200 font-semibold">Apportionment complete</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Legislatures sized</div>
                        <div class="text-white text-xl font-semibold mt-1">{{ summary.legislatures.toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Total seats apportioned</div>
                        <div class="text-white text-xl font-semibold mt-1">{{ summary.total_seats.toLocaleString() }}</div>
                    </div>
                    <div v-if="summary.largest">
                        <div class="text-gray-400 text-xs uppercase tracking-wide">Largest legislature</div>
                        <div class="text-white text-xl font-semibold mt-1">
                            {{ summary.largest.name }}
                            <span class="text-gray-400 text-sm font-normal">
                                · {{ summary.largest.seats.toLocaleString() }} seats
                            </span>
                        </div>
                    </div>
                </div>
                <!-- WI-9: enumerate the legislatures (setup creates one —
                     the root's; CLK-06 activations add more over time).
                     Each row links to its own district mapper. -->
                <div v-if="summary.rows?.length" class="mt-4 border-t border-emerald-800/50 pt-3">
                    <div class="text-gray-400 text-xs uppercase tracking-wide mb-2">Legislatures</div>
                    <ul class="space-y-1 text-sm">
                        <li v-for="leg in summary.rows" :key="leg.slug" class="flex items-baseline gap-2">
                            <a :href="`/legislatures/${leg.slug}`" class="text-emerald-300 hover:text-emerald-100 underline-offset-2 hover:underline">
                                {{ leg.name }}
                            </a>
                            <span class="text-gray-400 text-xs tabular-nums">
                                {{ (leg.type_a_seats + leg.type_b_seats).toLocaleString() }} seats
                                <template v-if="leg.type_b_seats > 0">
                                    ({{ leg.type_a_seats.toLocaleString() }} A + {{ leg.type_b_seats.toLocaleString() }} B)
                                </template>
                            </span>
                        </li>
                        <li v-if="summary.legislatures > summary.rows.length" class="text-gray-500 text-xs italic">
                            … and {{ (summary.legislatures - summary.rows.length).toLocaleString() }} more
                        </li>
                    </ul>
                </div>
                <p class="text-gray-400 text-xs mt-4 italic">
                    Cube-root sizing applied per Taagepera's law. Setup sizes the root legislature;
                    other jurisdictions get theirs when they activate at critical population (CLK-06).
                </p>
            </section>

            <div v-else-if="summaryError" class="bg-red-900/30 border border-red-800 rounded p-4 text-sm text-red-200 mb-6">
                {{ summaryError }}
            </div>

            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 space-y-4">
                <div v-if="!mapperHref" class="bg-amber-900/30 border border-amber-800 rounded p-4 text-sm text-amber-200">
                    <div class="font-semibold mb-1">No root legislature found.</div>
                    <p>Step 1 must finish loading at least ADM0 data before districts can be drawn. Go back to the map-data step to verify.</p>
                </div>

                <div v-else>
                    <p class="text-gray-300 mb-4">
                        The <strong>{{ root_jurisdiction?.name ?? 'root' }}</strong> legislature is ready
                        for districting. You'll be handed off to the interactive district mapper —
                        a map + sidebar where you can auto-seed districts from the cube-root sizing law,
                        hand-edit any you want to adjust, review quality metrics, and activate the map.
                    </p>

                    <ul class="text-sm text-gray-400 space-y-2 mb-5 pl-4 list-disc">
                        <li>A banner at the top of the mapper will remind you you're in setup mode.</li>
                        <li>When you activate a district map, the banner turns into a "Back to Setup →" button.</li>
                        <li>You can always come back here by visiting <code class="text-gray-300">/setup</code>.</li>
                    </ul>

                    <div class="flex items-center justify-between gap-4 pt-3 border-t border-gray-800">
                        <a href="/setup/step/2" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">
                            ← Back
                        </a>
                        <a
                            :href="mapperHref"
                            class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2 rounded-md font-semibold transition-colors inline-flex items-center gap-2"
                        >
                            Go to District Mapper →
                        </a>
                    </div>
                </div>
            </section>
    </div>
</template>
