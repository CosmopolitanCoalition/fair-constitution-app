<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
    category:       { type: String, required: true },
    jurisdictionId: { type: String, required: true },
})

const emit = defineEmits(['saved'])

const ADM_LABELS = {
    0: 'Planet', 1: 'Country', 2: 'State / Province', 3: 'County',
    4: 'Municipality', 5: 'Township', 6: 'Neighborhood',
}

const DECISION_VALUES = {
    population_gaps: {
        confirmed_zero:    'Confirmed zero (genuinely uninhabited)',
        will_fix_manually: 'Will fix manually (re-run ETL or edit DB)',
        unknown:           'Unknown — leave for later',
    },
    aggregation_discrepancies: {
        trust_national:   'Trust national value (children sum is wrong)',
        trust_children:   'Trust children sum (national value is wrong)',
        polygon_artifact: 'Polygon-precision artifact — accept as-is',
        investigate:      'Investigate further (not decided yet)',
    },
    orphans: {
        true_orphan:  'Genuinely top-level (no parent expected)',
        pick_parent:  'Chain to a candidate parent (note which one)',
        delete:       'Delete this row',
        unknown:      'Unknown — leave for later',
    },
    sovereign_territories: {
        will_load_raster:  'Will load this territory\'s WorldPop raster',
        treat_as_zero:     'Treat population as zero (intentional)',
        flag_for_phase_j:  'Flag for the Phase J auto-loader',
        unknown:           'Unknown — leave for later',
    },
}

const DETAIL_URL = (cat, id) =>
    `/api/setup/wizard/step2/review/${cat}/${id}/detail`
const DECISION_URL = (cat, id) =>
    `/api/setup/wizard/step2/review/${cat}/${id}/decision`

const detail   = ref(null)
const loading  = ref(false)
const error    = ref('')
const decision = ref('')
const note     = ref('')
const saving   = ref(false)
const savedOk  = ref(false)

function fmtInt(n) {
    if (n == null) return '—'
    return Number(n).toLocaleString()
}
function admLabel(lvl) {
    return ADM_LABELS[lvl] ?? `Level ${lvl}`
}
function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

const decisionOptions = computed(
    () => DECISION_VALUES[props.category] ?? {},
)

async function loadDetail() {
    loading.value = true
    error.value   = ''
    try {
        const res = await fetch(DETAIL_URL(props.category, props.jurisdictionId), {
            credentials: 'same-origin',
            headers:     { 'Accept': 'application/json' },
        })
        if (!res.ok) throw new Error(`HTTP ${res.status}`)
        detail.value = await res.json()
        // Pre-fill decision form from existing decision if any.
        if (detail.value.decision) {
            decision.value = detail.value.decision.decision || ''
            note.value     = detail.value.decision.note || ''
        } else {
            decision.value = ''
            note.value     = ''
        }
    } catch (e) {
        error.value = String(e.message || e)
    } finally {
        loading.value = false
    }
}

async function saveDecision() {
    if (!decision.value) {
        error.value = 'Pick a decision before saving.'
        return
    }
    saving.value  = true
    savedOk.value = false
    error.value   = ''
    try {
        const res = await fetch(DECISION_URL(props.category, props.jurisdictionId), {
            method:      'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                decision: decision.value,
                note:     note.value || null,
            }),
        })
        if (!res.ok) {
            const data = await res.json().catch(() => ({}))
            throw new Error(data.message || `HTTP ${res.status}`)
        }
        const payload = await res.json()
        // Reflect saved state inline
        if (detail.value) detail.value.decision = payload
        savedOk.value = true
        emit('saved', { category: props.category, jurisdictionId: props.jurisdictionId, payload })
    } catch (e) {
        error.value = String(e.message || e)
    } finally {
        saving.value = false
        // Hide the "Saved" tick after a moment so re-saves are obvious
        if (savedOk.value) {
            setTimeout(() => { savedOk.value = false }, 1800)
        }
    }
}

watch(
    () => [props.category, props.jurisdictionId],
    () => loadDetail(),
    { immediate: true },
)
</script>

<template>
    <div class="bg-gray-900 border-l-2 border-blue-500/60 rounded p-3 space-y-3">
        <div v-if="loading" class="text-gray-500 text-xs italic">Loading detail…</div>
        <div v-if="error && !loading" class="text-red-400 text-xs">{{ error }}</div>

        <template v-if="detail && !loading">
            <!-- ── Population gaps ─────────────────────────────────────────── -->
            <template v-if="category === 'population_gaps'">
                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                    <div class="text-gray-500">Name</div>
                    <div class="text-gray-200">{{ detail.row.name }}</div>
                    <div class="text-gray-500">ISO / Level</div>
                    <div class="text-gray-200">{{ detail.row.iso_code }} · {{ admLabel(detail.row.adm_level) }}</div>
                    <div class="text-gray-500">Parent</div>
                    <div class="text-gray-200">
                        {{ detail.row.parent_name || '—' }}
                        <span v-if="detail.row.parent_iso" class="text-gray-500">({{ detail.row.parent_iso }} · {{ admLabel(detail.row.parent_adm_level) }})</span>
                    </div>
                    <div class="text-gray-500">Area km²</div>
                    <div class="text-gray-200">{{ fmtInt(detail.row.area_km2) }}</div>
                    <div class="text-gray-500">Source</div>
                    <div class="text-gray-200">{{ detail.row.source }}</div>
                </div>

                <div v-if="detail.siblings?.length">
                    <div class="text-gray-500 text-[10px] uppercase tracking-wider mb-1">Siblings at this level</div>
                    <table class="w-full text-xs font-mono">
                        <thead class="text-gray-600 text-[10px] uppercase">
                            <tr>
                                <th class="text-left py-1">Name</th>
                                <th class="text-right py-1">Population</th>
                                <th class="text-right py-1">Area km²</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in detail.siblings" :key="s.id" class="border-t border-gray-800">
                                <td class="py-1 text-gray-300 truncate max-w-[220px]">{{ s.name }}</td>
                                <td class="py-1 text-right" :class="s.population > 0 ? 'text-emerald-300' : 'text-gray-500'">{{ fmtInt(s.population) }}</td>
                                <td class="py-1 text-right text-gray-500">{{ fmtInt(s.area_km2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- ── Aggregation discrepancies ─────────────────────────────── -->
            <template v-else-if="category === 'aggregation_discrepancies'">
                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                    <div class="text-gray-500">Country</div>
                    <div class="text-gray-200">{{ detail.parent.name }} ({{ detail.parent.iso_code }})</div>
                    <div class="text-gray-500">National population</div>
                    <div class="text-gray-200">{{ fmtInt(detail.rollup.parent_pop) }}</div>
                    <div class="text-gray-500">Children sum</div>
                    <div class="text-gray-200">{{ fmtInt(detail.rollup.children_sum) }}</div>
                    <div class="text-gray-500">Delta</div>
                    <div :class="(detail.rollup.delta_pct ?? 0) < 0 ? 'text-red-400' : 'text-amber-300'">
                        {{ fmtInt(detail.rollup.delta) }}
                        <span class="text-gray-600">·</span>
                        {{ detail.rollup.delta_pct }}%
                    </div>
                    <div class="text-gray-500">Children</div>
                    <div class="text-gray-200">{{ detail.rollup.children_with_pop }} of {{ detail.rollup.child_count }} have population</div>
                </div>

                <div>
                    <div class="text-gray-500 text-[10px] uppercase tracking-wider mb-1">Children (largest first)</div>
                    <div class="bg-black/40 rounded max-h-60 overflow-y-auto">
                        <table class="w-full text-xs font-mono">
                            <thead class="text-gray-600 text-[10px] uppercase sticky top-0 bg-black/80">
                                <tr>
                                    <th class="text-left py-1 px-2">Name</th>
                                    <th class="text-right py-1 px-2">Population</th>
                                    <th class="text-right py-1 px-2">Area km²</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in detail.children" :key="c.id" class="border-t border-gray-800">
                                    <td class="py-1 px-2 text-gray-300 truncate max-w-[220px]">{{ c.name }}</td>
                                    <td class="py-1 px-2 text-right" :class="c.population > 0 ? 'text-emerald-300' : 'text-red-400'">{{ fmtInt(c.population) }}</td>
                                    <td class="py-1 px-2 text-right text-gray-500">{{ fmtInt(c.area_km2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- ── Orphans ─────────────────────────────────────────────────── -->
            <template v-else-if="category === 'orphans'">
                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                    <div class="text-gray-500">Name</div>
                    <div class="text-gray-200">{{ detail.row.name }}</div>
                    <div class="text-gray-500">ISO / Level</div>
                    <div class="text-gray-200">{{ detail.row.iso_code }} · {{ admLabel(detail.row.adm_level) }}</div>
                    <div class="text-gray-500">Population</div>
                    <div class="text-gray-200">{{ fmtInt(detail.row.population) }}</div>
                    <div class="text-gray-500">Area km²</div>
                    <div class="text-gray-200">{{ fmtInt(detail.row.area_km2) }}</div>
                    <div class="text-gray-500">Source</div>
                    <div class="text-gray-200">{{ detail.row.source }}</div>
                </div>

                <div v-if="detail.spatial_candidates?.length">
                    <div class="text-gray-500 text-[10px] uppercase tracking-wider mb-1">Spatial overlap candidates (largest overlap first)</div>
                    <div class="space-y-1">
                        <div
                            v-for="c in detail.spatial_candidates"
                            :key="'sp-' + c.id"
                            class="bg-black/40 border border-gray-800 rounded px-2 py-1 text-xs font-mono flex items-center justify-between"
                        >
                            <div class="text-gray-200 truncate">
                                {{ c.name }}
                                <span class="text-gray-500">· {{ c.iso_code }} · {{ admLabel(c.adm_level) }}</span>
                            </div>
                            <div class="text-emerald-300/80 whitespace-nowrap">
                                {{ fmtInt(c.overlap_km2) }} km² overlap
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="text-amber-300/70 text-xs italic">
                    No spatial overlap candidates found at lower levels with a matching ISO or sovereign.
                </div>

                <div v-if="detail.centroid_candidates?.length">
                    <div class="text-gray-500 text-[10px] uppercase tracking-wider mb-1">Nearest-centroid candidates</div>
                    <div class="space-y-1">
                        <div
                            v-for="c in detail.centroid_candidates"
                            :key="'cn-' + c.id"
                            class="bg-black/40 border border-gray-800 rounded px-2 py-1 text-xs font-mono flex items-center justify-between"
                        >
                            <div class="text-gray-200 truncate">
                                {{ c.name }}
                                <span class="text-gray-500">· {{ c.iso_code }} · {{ admLabel(c.adm_level) }}</span>
                            </div>
                            <div class="text-blue-300/80 whitespace-nowrap">
                                {{ fmtInt(c.distance_km) }} km
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── Sovereign-territory candidates ──────────────────────── -->
            <template v-else-if="category === 'sovereign_territories'">
                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                    <div class="text-gray-500">Territory name</div>
                    <div class="text-gray-200">{{ detail.row.name }}</div>
                    <div class="text-gray-500">Tagged under sovereign</div>
                    <div class="text-gray-200">{{ detail.sovereign }}</div>
                    <div class="text-gray-500">Inferred territory ISO</div>
                    <div class="text-gray-200">{{ detail.territory_iso }}</div>
                    <div class="text-gray-500">Children at this row</div>
                    <div class="text-gray-200">
                        {{ fmtInt(detail.row.child_count) }}
                        <span class="text-gray-500" v-if="detail.row.children_at_zero != null">
                            (incl. {{ fmtInt(detail.row.children_at_zero) }} also at 0 pop)
                        </span>
                    </div>
                    <div class="text-gray-500">Area km²</div>
                    <div class="text-gray-200">{{ fmtInt(detail.row.area_km2) }}</div>
                </div>

                <div class="bg-black/40 border border-gray-800 rounded p-2 text-xs font-mono">
                    <div class="text-gray-500 text-[10px] uppercase tracking-wider mb-1">WorldPop raster availability</div>
                    <div v-if="detail.raster_available === true" class="text-emerald-300">
                        ✓ Found at <span class="text-gray-300">{{ detail.raster_path_hint }}</span>
                    </div>
                    <div v-else-if="detail.raster_available === false" class="text-red-400">
                        ✗ Not found at <span class="text-gray-300">{{ detail.raster_path_hint }}</span>
                    </div>
                    <div v-else class="text-gray-500 italic">
                        Archive not visible from PHP — check manually inside the etl container.
                    </div>
                </div>

                <div v-if="detail.siblings?.length">
                    <div class="text-gray-500 text-[10px] uppercase tracking-wider mb-1">Siblings under {{ detail.sovereign }} with similar population</div>
                    <table class="w-full text-xs font-mono">
                        <thead class="text-gray-600 text-[10px] uppercase">
                            <tr>
                                <th class="text-left py-1">Name</th>
                                <th class="text-right py-1">Population</th>
                                <th class="text-right py-1">Area km²</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in detail.siblings" :key="s.id" class="border-t border-gray-800">
                                <td class="py-1 text-gray-300 truncate max-w-[220px]">{{ s.name }}</td>
                                <td class="py-1 text-right text-emerald-300">{{ fmtInt(s.population) }}</td>
                                <td class="py-1 text-right text-gray-500">{{ fmtInt(s.area_km2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- ── Decision form (shared across categories) ────────────── -->
            <div class="border-t border-gray-800 pt-3 mt-3 space-y-2">
                <div class="text-gray-300 text-xs font-semibold flex items-center justify-between">
                    <span>Your decision</span>
                    <span
                        v-if="detail.decision"
                        class="text-[10px] font-mono text-gray-500"
                    >
                        Last saved: {{ new Date(detail.decision.updated_at).toLocaleString() }}
                    </span>
                </div>

                <div class="space-y-1">
                    <label
                        v-for="(label, value) in decisionOptions"
                        :key="value"
                        class="flex items-start gap-2 text-xs cursor-pointer hover:bg-gray-950 rounded px-1 py-0.5"
                    >
                        <input
                            type="radio"
                            :value="value"
                            v-model="decision"
                            class="mt-0.5"
                        />
                        <span class="text-gray-200">{{ label }}</span>
                    </label>
                </div>

                <textarea
                    v-model="note"
                    rows="2"
                    placeholder="Optional note — what did you decide and why?"
                    class="w-full bg-gray-950 border border-gray-800 rounded px-2 py-1 text-xs font-mono text-gray-200 placeholder-gray-600 focus:border-blue-700 focus:outline-none"
                />

                <div class="flex items-center justify-between gap-2">
                    <span class="text-[10px] font-mono text-gray-500">
                        No autofix — saving records your decision for later review or remediation.
                    </span>
                    <button
                        type="button"
                        @click="saveDecision"
                        :disabled="saving || !decision"
                        class="text-xs px-3 py-1 rounded bg-blue-700 hover:bg-blue-600 disabled:bg-gray-700 disabled:cursor-not-allowed text-white font-semibold"
                    >
                        {{ saving ? 'Saving…' : (savedOk ? '✓ Saved' : 'Save decision') }}
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
