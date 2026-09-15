<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    byLevel:      { type: [Array, Object], default: () => [] },
    total:        { type: Number, default: 0 },
    totalWithPop: { type: Number, default: 0 },
    totalSumPop:  { type: Number, default: 0 },
    // Optional hint string from heartbeat (e.g. "loading population data (...)").
    // Used to show a one-line note that populations recompute after raster load,
    // so the user doesn't read 0% as a regression during raster loading.
    subPhaseHint: { type: String, default: '' },
})

const showRasterLoadHint = computed(() => {
    const s = (props.subPhaseHint || '').toLowerCase()
    return s.includes('loading raster') || s.includes('loading population')
})

const entries = computed(() => {
    const raw = props.byLevel
    if (Array.isArray(raw)) {
        return [...raw].sort((a, b) => Number(a.level) - Number(b.level))
    }
    return Object.keys(raw || {})
        .sort((a, b) => Number(a) - Number(b))
        .map(k => ({
            level:    Number(k),
            label:    raw[k]?.label ?? t('c_setup_components.jurisdiction_counts_grid.level_n', { level: k }),
            count:    raw[k]?.count ?? 0,
            with_pop: raw[k]?.with_pop ?? 0,
            sum_pop:  raw[k]?.sum_pop ?? 0,
        }))
})

function fmt(n) {
    return localeFmt.number(Number(n))
}

// Since boundary loading always finishes before population computation starts,
// the count at each level stops growing after phase 1, and with_pop climbs
// toward count during phase 2 — the ratio is a clean "how far into population
// computation is this level" progress indicator without needing phase-aware totals.
function pct(withPop, count) {
    if (!count) return null
    return Math.round((withPop / count) * 100)
}
</script>

<template>
    <div
        v-if="showRasterLoadHint"
        class="text-[11px] font-mono text-emerald-300/70 mb-2"
    >
        {{ t('c_setup_components.jurisdiction_counts_grid.raster_load_hint', 'Populations compute after raster load completes — these counters stay at 0% until the population tiles finish loading into the database.') }}
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div
            v-for="e in entries"
            :key="e.level"
            class="bg-gray-950 border border-gray-800 rounded p-2 relative overflow-hidden"
        >
            <!-- Population progress bar behind the numbers -->
            <div
                v-if="pct(e.with_pop, e.count) != null"
                class="absolute inset-y-0 left-0 bg-emerald-900/20 pointer-events-none"
                :style="{ width: pct(e.with_pop, e.count) + '%' }"
            />
            <div class="relative">
                <div class="text-gray-400 text-xs">
                    {{ e.label }}
                </div>
                <div class="text-gray-100 font-mono text-lg leading-tight">
                    {{ fmt(e.count) }}
                </div>
                <div
                    v-if="e.count > 0"
                    class="text-[10px] font-mono mt-0.5"
                    :class="pct(e.with_pop, e.count) === 100 ? 'text-emerald-400' : 'text-emerald-500/80'"
                >
                    {{ fmt(e.with_pop) }} {{ t('c_setup_components.jurisdiction_counts_grid.with_pop', 'w/ pop') }}
                    <span v-if="pct(e.with_pop, e.count) != null" class="text-gray-600">
                        ({{ pct(e.with_pop, e.count) }}%)
                    </span>
                </div>
                <div
                    v-if="e.sum_pop > 0"
                    class="text-[10px] font-mono mt-0.5 text-emerald-200/80"
                    :title="t('c_setup_components.jurisdiction_counts_grid.sum_at_level_title', 'Sum of populations at this level')"
                >
                    Σ {{ fmt(e.sum_pop) }}
                </div>
            </div>
        </div>
        <div class="bg-gray-950 border border-blue-900/40 rounded p-2 relative overflow-hidden">
            <div
                v-if="total > 0"
                class="absolute inset-y-0 left-0 bg-emerald-900/20 pointer-events-none"
                :style="{ width: pct(totalWithPop, total) + '%' }"
            />
            <div class="relative">
                <div class="text-blue-500 text-xs">{{ t('c_setup_components.jurisdiction_counts_grid.total_rows', 'Total rows') }}</div>
                <div class="text-blue-200 font-mono text-lg leading-tight">{{ fmt(total) }}</div>
                <div
                    v-if="total > 0"
                    class="text-[10px] font-mono mt-0.5"
                    :class="pct(totalWithPop, total) === 100 ? 'text-emerald-400' : 'text-emerald-500/80'"
                >
                    {{ fmt(totalWithPop) }} {{ t('c_setup_components.jurisdiction_counts_grid.with_pop', 'w/ pop') }}
                    <span v-if="pct(totalWithPop, total) != null" class="text-gray-600">
                        ({{ pct(totalWithPop, total) }}%)
                    </span>
                </div>
                <div
                    v-if="totalSumPop > 0"
                    class="text-[10px] font-mono mt-0.5 text-blue-200/80"
                    :title="t('c_setup_components.jurisdiction_counts_grid.grand_total_title', 'Grand total of populations across all levels')"
                >
                    Σ {{ fmt(totalSumPop) }}
                </div>
            </div>
        </div>
    </div>
</template>
