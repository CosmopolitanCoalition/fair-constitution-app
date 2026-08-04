<script setup>
/**
 * The acceptance scan's six detector bars.
 *
 * WHERE THIS BELONGS. These bars used to sit inside the ingestion panel,
 * beside the boundary/raster/resolve/attribution lanes. They looked like more
 * ingestion work but they aren't — they are the map health measurement, and
 * their output is the flag queue in Review & Accept. Operator caught the
 * mismatch by trying to expand a detector and having nothing happen: the
 * findings were in a different section entirely. So the bars live with the
 * findings now, and the ingestion panel is purely the work of ingesting.
 *
 * Each detector key IS the flag category, so every bar carries the same
 * explainer the flag queue shows for that check — one source (lib/mapHealth),
 * two surfaces, no drift.
 */
import { computed } from 'vue'
import { NATURE_BADGE, describeCheck } from '@/lib/mapHealth'

const props = defineProps({
    detectors: { type: Array, default: () => [] },
})

const doneCount = computed(() => props.detectors.filter((d) => d.state === 'done').length)
const donePct   = computed(() => (props.detectors.length === 0
    ? 0
    : Math.round((doneCount.value / props.detectors.length) * 100)))

const NATURE_CHIPS = {
    structural:    'bg-red-950/60 text-red-300 border-red-800',
    reality:       'bg-sky-950/60 text-sky-300 border-sky-800',
    informational: 'bg-gray-800 text-gray-400 border-gray-700',
}

function natureBadge(key) {
    return NATURE_BADGE[describeCheck(key).nature] ?? NATURE_BADGE.informational
}
function natureChip(key) {
    return NATURE_CHIPS[describeCheck(key).nature] ?? NATURE_CHIPS.informational
}
</script>

<template>
    <div v-if="detectors.length > 0">
        <div class="flex items-baseline justify-between mb-2">
            <h3 class="text-gray-200 text-xs font-semibold uppercase tracking-wide">
                Map health scan — {{ doneCount }} / {{ detectors.length }} checks
            </h3>
            <span class="text-gray-500 text-[10px]">
                hover a check for what it measures
            </span>
        </div>

        <div class="h-2 bg-gray-800 rounded overflow-hidden mb-1.5">
            <div class="h-full rounded bg-sky-500 transition-all duration-500"
                 :style="{ width: donePct + '%' }" />
        </div>

        <ul class="space-y-1 pl-4 border-l border-gray-800">
            <li v-for="d in detectors" :key="d.key"
                class="relative group text-xs bg-gray-800/50 rounded px-2.5 py-1.5">
                <div class="flex items-center justify-between mb-1">
                    <span class="flex items-center gap-2 min-w-0">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0"
                              :class="{
                                  'bg-emerald-400': d.state === 'done',
                                  'bg-sky-400 animate-pulse': d.state === 'running',
                                  'bg-amber-400': d.state === 'stalled',
                                  'bg-red-400': d.state === 'error',
                                  'bg-gray-600': d.state === 'pending',
                              }" aria-hidden="true" />
                        <!-- Shared label, not the backend's ucwords() of the
                             category — otherwise the bar says "Mis Anchored
                             Cluster" and the queue below says "Mis-anchored
                             clusters" for the same check. -->
                        <span class="text-gray-200 font-medium">{{ describeCheck(d.key).label || d.label }}</span>
                        <span class="px-1 py-0 rounded text-[9px] border shrink-0"
                              :class="natureChip(d.key)">
                            {{ natureBadge(d.key).text }}
                        </span>
                        <span class="text-gray-600 text-[9px] cursor-help select-none">?</span>
                    </span>
                    <span class="tabular-nums shrink-0 ml-3"
                          :class="d.state === 'error' ? 'text-red-400'
                                : d.state === 'stalled' ? 'text-amber-400' : 'text-gray-500'">
                        <template v-if="d.state === 'done'">{{ d.flags.toLocaleString() }} flag{{ d.flags === 1 ? '' : 's' }}</template>
                        <template v-else-if="d.state === 'running'">{{ Math.floor(d.elapsed_s / 60) }}m {{ d.elapsed_s % 60 }}s</template>
                        <template v-else-if="d.state === 'stalled'">stalled {{ Math.floor(d.elapsed_s / 60) }}m — will retry</template>
                        <template v-else-if="d.state === 'error'">errored</template>
                        <template v-else>queued</template>
                    </span>
                </div>
                <div class="h-1.5 bg-gray-900 rounded overflow-hidden">
                    <div v-if="d.state === 'done'" class="h-full w-full rounded bg-emerald-600" />
                    <div v-else-if="d.state === 'running'" class="h-full w-1/4 rounded bg-sky-800 animate-pulse" />
                    <div v-else-if="d.state === 'stalled'" class="h-full w-1/4 rounded bg-amber-800" />
                    <div v-else-if="d.state === 'error'" class="h-full w-full rounded bg-red-900" />
                </div>

                <div class="pointer-events-none absolute left-4 top-full mt-0.5 z-50 w-72 rounded bg-gray-700 border border-gray-600 p-2 text-[10px] text-gray-300 leading-snug hidden group-hover:block shadow-lg space-y-1">
                    <div><span class="text-gray-400 font-semibold">Measures.</span> {{ describeCheck(d.key).measures }}</div>
                    <div><span class="text-gray-400 font-semibold">Why it matters.</span> {{ describeCheck(d.key).why }}</div>
                    <div><span class="text-gray-400 font-semibold">How to read it.</span> {{ describeCheck(d.key).reading }}</div>
                    <div v-if="describeCheck(d.key).remedy">
                        <span class="text-gray-400 font-semibold">Default remedy.</span> {{ describeCheck(d.key).remedy }}
                    </div>
                    <div class="pt-1 border-t border-gray-600 text-gray-400">
                        {{ natureBadge(d.key).hint }}
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
