<script setup>
import { computed, ref } from 'vue'
import RowDetailPanel from './RowDetailPanel.vue'

const props = defineProps({
    review: { type: Object, required: true },
})

// ── Severity styling ─────────────────────────────────────────────────────────

const severityTone = computed(() => ({
    low:    { pill: 'bg-emerald-900/40 border-emerald-700 text-emerald-200', icon: '✓' },
    medium: { pill: 'bg-amber-900/40 border-amber-700 text-amber-200',       icon: '⚠' },
    high:   { pill: 'bg-red-900/40 border-red-700 text-red-200',             icon: '✗' },
}[props.review?.severity ?? 'low']))

// ── Category metadata ────────────────────────────────────────────────────────

const ADM_LABELS = {
    0: 'Planet',
    1: 'Country',
    2: 'State / Province',
    3: 'County',
    4: 'Municipality',
    5: 'Township',
    6: 'Neighborhood',
}

const categories = computed(() => {
    const issues = props.review?.issues ?? {}
    return [
        {
            key:   'population_gaps',
            title: 'Population gaps',
            icon:  '🌫',
            count: issues.population_gaps?.count ?? 0,
            why:   "Jurisdictions with population = 0 or NULL. Most are legitimately uninhabited rural cells at the deepest ADM levels; tighter levels (Country, State, County) deserve closer review — those are usually territory-related.",
            byLevel: issues.population_gaps?.by_level ?? [],
            endpoint: '/api/setup/wizard/step2/review/population_gaps',
            // population_gaps drills are scoped per adm_level — pick one
            requiresLevel: true,
            defaultLevel:  3,   // counties = good middle-ground default
        },
        {
            key:   'aggregation_discrepancies',
            title: 'Aggregation discrepancies',
            icon:  '⚖',
            count: issues.aggregation_discrepancies?.count ?? 0,
            why:   "Country-level rollup where the parent's national population disagrees with the sum of its children's populations by more than 5%. Usually polygon-precision drift between geoBoundaries' ADM levels — not a pipeline bug.",
            endpoint: '/api/setup/wizard/step2/review/aggregation_discrepancies',
            requiresLevel: false,
        },
        {
            key:   'orphans',
            title: 'Orphan jurisdictions',
            icon:  '🪐',
            count: issues.orphans?.count ?? 0,
            why:   "Rows with parent_id = NULL — find_parent_by_spatial failed at import time, usually for island enclaves or where an intermediate ADM level is missing from geoBoundaries (e.g. Puerto Rico has no ADM0/1 row in the source). Click any row below to see candidate parents (spatial-overlap and centroid-distance) and record your decision.",
            byLevel: issues.orphans?.by_level ?? [],
            topIso:  issues.orphans?.top_iso ?? [],
            endpoint: '/api/setup/wizard/step2/review/orphans',
            requiresLevel: false,
        },
        {
            key:   'sovereign_territories',
            title: 'Sovereign-territory candidates',
            icon:  '🏛',
            count: issues.sovereign_territories?.count ?? 0,
            why:   "Territory rows tagged with a sovereign's iso_code (e.g. USA) that have population=0 because the territory's own WorldPop raster wasn't loaded. With Phase K's territory-raster fallback shipped, this count should drop to ~0 after the next fresh ETL — any residual entries surface here for inspection.",
            bySovereign: issues.sovereign_territories?.territory_count_by_sovereign ?? {},
            endpoint: '/api/setup/wizard/step2/review/sovereign_territories',
            requiresLevel: false,
        },
        // ── Phase JK audit cards ────────────────────────────────────────
        {
            key:   'parent_assignment_audit',
            title: 'Auto-resolved parents (Phase J audit)',
            icon:  '🧭',
            count: issues.parent_assignment_audit?.count ?? 0,
            why:   "Rows by parent-resolution strategy. 'direct' is the normal case (parent is one level shallower, no skip needed). 'skip_ancestor' means a level was skipped (e.g. CAF level-6 → CAF level-4 because level-5 doesn't exist for CAF in geoBoundaries). 'buffered' means a 110m tolerance was needed for the polygons to overlap (digitization drift between adjacent ADM levels). Click a chip to drill in. The card's headline count tracks heuristic-resolved rows only ('skip_ancestor' + 'buffered'); 'direct' is informational.",
            byStrategy: issues.parent_assignment_audit?.by_strategy ?? {},
            endpoint:   '/api/setup/wizard/step2/review/parent_assignment_audit',
            requiresStrategy: true,
        },
        {
            key:   'population_assignment_audit',
            title: 'Population resolution (Phase K audit)',
            icon:  '📡',
            count: issues.population_assignment_audit?.count ?? 0,
            why:   "Rows whose population came from the territory-raster fallback rather than their own iso's raster. Typically PR municipios under USA, where USA's raster doesn't cover PR but PRI's does. 'primary' means the row's own-iso raster matched directly. Click a source chip to drill in.",
            bySource: issues.population_assignment_audit?.by_source ?? {},
            endpoint: '/api/setup/wizard/step2/review/population_assignment_audit',
            requiresSource: true,
        },
    ]
})

const ALWAYS_SHOW_KEYS = new Set(['parent_assignment_audit', 'population_assignment_audit'])

const visibleCategories = computed(() =>
    categories.value.filter(c => c.count > 0 || ALWAYS_SHOW_KEYS.has(c.key))
)

// Total "issues" excludes the audit cards — those are informational, not issues.
const totalIssues = computed(() =>
    visibleCategories.value
        .filter(c => !ALWAYS_SHOW_KEYS.has(c.key))
        .reduce((s, c) => s + c.count, 0)
)

// ── Per-category expansion / fetch state ─────────────────────────────────────

const expanded     = ref({})            // key → bool (category card open?)
const fetchedRows  = ref({})            // key → array
const totals       = ref({})            // key → int
const nextOffsets  = ref({})            // key → int|null
const loading      = ref({})            // key → bool
const fetchError   = ref({})            // key → string
const filterLevel    = ref({})          // key → number|null
const filterSov      = ref({})          // key → string|null
const filterStrategy = ref({})          // key → string|null (Phase J audit)
const filterSource   = ref({})          // key → string|null (Phase K audit)
const acknowledged = ref({})            // key → bool (client-side only for v1)
const expandedRow  = ref({})            // key → row.id (currently-expanded row per category)
const decidedRows  = ref({})            // key → Set<row.id> (rows that have been decided this session)

async function loadRows(cat, append = false) {
    const offset = append ? (nextOffsets.value[cat.key] ?? 0) : 0
    if (append && offset === null) return

    // Audit cards require an explicit chip selection before any rows can
    // be fetched (the drill endpoint requires the strategy/source param).
    if (cat.requiresStrategy && !filterStrategy.value[cat.key]) return
    if (cat.requiresSource && !filterSource.value[cat.key]) return

    loading.value[cat.key]    = true
    fetchError.value[cat.key] = ''
    try {
        const qs = new URLSearchParams({ limit: '50', offset: String(offset) })
        if (cat.key === 'population_gaps') {
            const lvl = filterLevel.value[cat.key] ?? cat.defaultLevel
            qs.set('adm_level', String(lvl))
        }
        if (cat.key === 'orphans' && filterLevel.value[cat.key] != null) {
            qs.set('adm_level', String(filterLevel.value[cat.key]))
        }
        if (cat.key === 'sovereign_territories' && filterSov.value[cat.key]) {
            qs.set('sovereign', filterSov.value[cat.key])
        }
        if (cat.requiresStrategy && filterStrategy.value[cat.key]) {
            qs.set('strategy', filterStrategy.value[cat.key])
        }
        if (cat.requiresSource && filterSource.value[cat.key]) {
            qs.set('source', filterSource.value[cat.key])
        }
        const res = await fetch(`${cat.endpoint}?${qs}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) throw new Error(`HTTP ${res.status}`)
        const data = await res.json()
        const incoming = Array.isArray(data.rows) ? data.rows : []
        fetchedRows.value[cat.key] = append
            ? [ ...(fetchedRows.value[cat.key] ?? []), ...incoming ]
            : incoming
        totals.value[cat.key]      = data.total ?? 0
        nextOffsets.value[cat.key] = data.next_offset ?? null
    } catch (e) {
        fetchError.value[cat.key] = String(e.message || e)
    } finally {
        loading.value[cat.key] = false
    }
}

function toggleExpand(cat) {
    expanded.value[cat.key] = !expanded.value[cat.key]
    if (expanded.value[cat.key] && !fetchedRows.value[cat.key]) {
        loadRows(cat, false)
    }
}

function changeLevel(cat, lvl) {
    filterLevel.value[cat.key] = lvl
    fetchedRows.value[cat.key] = null
    loadRows(cat, false)
}

function changeSovereign(cat, sov) {
    filterSov.value[cat.key] = sov
    fetchedRows.value[cat.key] = null
    loadRows(cat, false)
}

function changeStrategy(cat, strategy) {
    filterStrategy.value[cat.key] = strategy
    fetchedRows.value[cat.key] = null
    loadRows(cat, false)
}

function changeSource(cat, source) {
    filterSource.value[cat.key] = source
    fetchedRows.value[cat.key] = null
    loadRows(cat, false)
}

const STRATEGY_LABELS = {
    direct:              'Direct (parent at level-1 shallower)',
    skip_ancestor:       'Skip-to-ancestor (level gap)',
    buffered:            'Buffered (110m tolerance)',
    orphan_or_pre_jk:    'Orphan / pre-JK',
}
const SOURCE_LABELS = {
    primary:               'Primary raster',
    territory_fallback:    'Territory fallback',
    no_data_or_pre_jk:     'No data / pre-JK',
}

function acknowledge(cat) {
    acknowledged.value[cat.key] = true
    expanded.value[cat.key] = false
}

function toggleRow(cat, rowId) {
    if (expandedRow.value[cat.key] === rowId) {
        expandedRow.value[cat.key] = null
    } else {
        expandedRow.value[cat.key] = rowId
    }
}

function onRowDecisionSaved(cat, rowId) {
    if (!decidedRows.value[cat.key]) decidedRows.value[cat.key] = new Set()
    decidedRows.value[cat.key].add(rowId)
    // Force reactivity since we're mutating a Set
    decidedRows.value = { ...decidedRows.value }
}

function isRowDecided(catKey, rowId) {
    return decidedRows.value[catKey]?.has(rowId) ?? false
}

function fmtInt(n) {
    return Number(n ?? 0).toLocaleString()
}

function admLabel(lvl) {
    return ADM_LABELS[lvl] ?? `Level ${lvl}`
}
</script>

<template>
    <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="text-white font-semibold mb-1">Data quality review</h2>
                <p class="text-gray-400 text-sm">
                    The ETL surfaced
                    <span
                        v-if="visibleCategories.length"
                        class="text-white font-mono"
                    >{{ fmtInt(totalIssues) }}</span>
                    <span v-else class="text-emerald-300 font-mono">no</span>
                    potential issue{{ totalIssues === 1 ? '' : 's' }}
                    <span v-if="visibleCategories.length">across {{ visibleCategories.length }} {{ visibleCategories.length === 1 ? 'category' : 'categories' }}</span>.
                    Review them below or accept and finish setup — issues are informational, not blocking.
                </p>
            </div>
            <span
                v-if="review?.severity"
                class="text-xs uppercase tracking-wider font-mono px-2 py-1 rounded border whitespace-nowrap"
                :class="severityTone.pill"
            >
                {{ severityTone.icon }} {{ review.severity }}
            </span>
        </div>

        <!-- Coverage line -->
        <div
            v-if="review?.totals"
            class="text-xs font-mono text-gray-500 mb-4"
        >
            {{ fmtInt(review.totals.with_population) }} of {{ fmtInt(review.totals.jurisdictions) }} jurisdictions have population
            (<span class="text-emerald-400">{{ review.totals.pct_with_population }}%</span>)
        </div>

        <!-- Empty state -->
        <div
            v-if="!visibleCategories.length"
            class="text-emerald-300 text-sm py-6 text-center bg-emerald-900/20 border border-emerald-800 rounded"
        >
            ✓ No data quality issues detected. Ready to finish setup.
        </div>

        <!-- Category cards -->
        <div v-else class="space-y-3">
            <div
                v-for="cat in visibleCategories"
                :key="cat.key"
                class="border border-gray-800 rounded-md overflow-hidden bg-gray-950"
                :class="{ 'opacity-60': acknowledged[cat.key] }"
            >
                <!-- Header row (clickable to expand) -->
                <button
                    type="button"
                    @click="toggleExpand(cat)"
                    class="w-full text-left p-3 flex items-center justify-between gap-3 hover:bg-gray-900/50 transition-colors"
                    :disabled="acknowledged[cat.key]"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="text-xl">{{ cat.icon }}</span>
                        <div class="min-w-0">
                            <div class="text-white font-semibold text-sm flex items-center gap-2">
                                {{ cat.title }}
                                <span
                                    v-if="acknowledged[cat.key]"
                                    class="text-emerald-400 font-mono text-[10px]"
                                >✓ acknowledged</span>
                            </div>
                            <div class="text-gray-500 text-xs">
                                {{ fmtInt(cat.count) }} {{ cat.count === 1 ? 'row' : 'rows' }}
                            </div>
                        </div>
                    </div>
                    <span
                        class="text-gray-500 text-lg font-mono leading-none"
                        :class="{ 'rotate-90': expanded[cat.key] }"
                        style="transition: transform 200ms"
                    >▸</span>
                </button>

                <!-- Expanded content -->
                <div
                    v-if="expanded[cat.key] && !acknowledged[cat.key]"
                    class="border-t border-gray-800 p-3 space-y-3"
                >
                    <!-- Why this matters -->
                    <p class="text-gray-400 text-xs leading-relaxed">{{ cat.why }}</p>

                    <!-- Per-level breakdown chips for population_gaps + orphans -->
                    <div v-if="cat.byLevel?.length" class="flex flex-wrap gap-1.5">
                        <button
                            v-for="lvl in cat.byLevel.filter(l => (l.without_pop ?? l.count ?? 0) > 0)"
                            :key="lvl.adm_level"
                            type="button"
                            @click="changeLevel(cat, lvl.adm_level)"
                            class="text-[10px] font-mono px-2 py-1 rounded border"
                            :class="(filterLevel[cat.key] ?? cat.defaultLevel) === lvl.adm_level
                                ? 'bg-blue-900/40 border-blue-700 text-blue-200'
                                : 'bg-gray-900 border-gray-800 text-gray-400 hover:border-gray-700'"
                        >
                            {{ admLabel(lvl.adm_level) }}
                            <span class="text-gray-600">·</span>
                            {{ fmtInt(lvl.without_pop ?? lvl.count) }}
                        </button>
                    </div>

                    <!-- Top-ISO chips for orphans -->
                    <div v-if="cat.topIso?.length" class="flex flex-wrap gap-1.5">
                        <span class="text-[10px] font-mono text-gray-500 self-center">Top countries:</span>
                        <span
                            v-for="iso in cat.topIso"
                            :key="iso.iso_code"
                            class="text-[10px] font-mono px-2 py-1 rounded border bg-gray-900 border-gray-800 text-gray-300"
                        >
                            {{ iso.iso_code }} <span class="text-gray-600">·</span> {{ fmtInt(iso.count) }}
                        </span>
                    </div>

                    <!-- Sovereign filter for sovereign_territories -->
                    <div
                        v-if="cat.key === 'sovereign_territories' && Object.keys(cat.bySovereign).length"
                        class="flex flex-wrap gap-1.5"
                    >
                        <button
                            type="button"
                            @click="changeSovereign(cat, null)"
                            class="text-[10px] font-mono px-2 py-1 rounded border"
                            :class="!filterSov[cat.key]
                                ? 'bg-blue-900/40 border-blue-700 text-blue-200'
                                : 'bg-gray-900 border-gray-800 text-gray-400 hover:border-gray-700'"
                        >All ({{ fmtInt(cat.count) }})</button>
                        <button
                            v-for="(n, sov) in cat.bySovereign"
                            :key="sov"
                            type="button"
                            @click="changeSovereign(cat, sov)"
                            class="text-[10px] font-mono px-2 py-1 rounded border"
                            :class="filterSov[cat.key] === sov
                                ? 'bg-blue-900/40 border-blue-700 text-blue-200'
                                : 'bg-gray-900 border-gray-800 text-gray-400 hover:border-gray-700'"
                        >{{ sov }} <span class="text-gray-600">·</span> {{ fmtInt(n) }}</button>
                    </div>

                    <!-- Strategy filter for parent_assignment_audit (Phase J) -->
                    <div
                        v-if="cat.key === 'parent_assignment_audit' && Object.keys(cat.byStrategy).length"
                        class="flex flex-wrap gap-1.5"
                    >
                        <span class="text-[10px] font-mono text-gray-500 self-center">
                            Click a strategy to drill into rows resolved that way:
                        </span>
                        <button
                            v-for="(n, strat) in cat.byStrategy"
                            :key="strat"
                            type="button"
                            @click="changeStrategy(cat, strat)"
                            class="text-[10px] font-mono px-2 py-1 rounded border"
                            :class="filterStrategy[cat.key] === strat
                                ? 'bg-blue-900/40 border-blue-700 text-blue-200'
                                : 'bg-gray-900 border-gray-800 text-gray-400 hover:border-gray-700'"
                        >{{ STRATEGY_LABELS[strat] || strat }} <span class="text-gray-600">·</span> {{ fmtInt(n) }}</button>
                    </div>

                    <!-- Source filter for population_assignment_audit (Phase K) -->
                    <div
                        v-if="cat.key === 'population_assignment_audit' && Object.keys(cat.bySource).length"
                        class="flex flex-wrap gap-1.5"
                    >
                        <span class="text-[10px] font-mono text-gray-500 self-center">
                            Click a source to drill into rows whose population came from it:
                        </span>
                        <button
                            v-for="(n, src) in cat.bySource"
                            :key="src"
                            type="button"
                            @click="changeSource(cat, src)"
                            class="text-[10px] font-mono px-2 py-1 rounded border"
                            :class="filterSource[cat.key] === src
                                ? 'bg-blue-900/40 border-blue-700 text-blue-200'
                                : 'bg-gray-900 border-gray-800 text-gray-400 hover:border-gray-700'"
                        >{{ SOURCE_LABELS[src] || src }} <span class="text-gray-600">·</span> {{ fmtInt(n) }}</button>
                    </div>

                    <!-- Loading / error -->
                    <div v-if="loading[cat.key]" class="text-gray-500 text-xs italic">Loading rows…</div>
                    <div v-if="fetchError[cat.key]" class="text-red-400 text-xs">{{ fetchError[cat.key] }}</div>

                    <!-- Row table — click any row to expand for full detail + decision form -->
                    <div
                        v-if="fetchedRows[cat.key]?.length"
                        class="bg-black/40 border border-gray-800 rounded overflow-x-auto"
                    >
                        <table class="w-full text-xs font-mono text-gray-300">
                            <thead class="text-gray-500 text-[10px] uppercase">
                                <tr>
                                    <th class="w-6 px-1 py-1.5"></th>
                                    <th class="text-left px-2 py-1.5">Name</th>
                                    <th class="text-left px-2 py-1.5">ISO</th>
                                    <th class="text-left px-2 py-1.5">Level</th>
                                    <th class="text-right px-2 py-1.5" v-if="cat.key === 'aggregation_discrepancies'">Δ</th>
                                    <th class="text-right px-2 py-1.5" v-if="cat.key === 'aggregation_discrepancies'">Δ %</th>
                                    <th class="text-right px-2 py-1.5" v-if="cat.key !== 'aggregation_discrepancies'">Population</th>
                                    <th class="text-right px-2 py-1.5" v-if="cat.key !== 'aggregation_discrepancies'">Area km²</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="row in fetchedRows[cat.key]" :key="row.id">
                                    <tr
                                        class="border-t border-gray-900 hover:bg-gray-900/40 cursor-pointer"
                                        :class="{ 'bg-blue-900/20': expandedRow[cat.key] === row.id }"
                                        @click="toggleRow(cat, row.id)"
                                    >
                                        <td class="px-1 py-1 text-center">
                                            <span
                                                class="text-gray-500 inline-block leading-none"
                                                :class="{ 'rotate-90 text-gray-200': expandedRow[cat.key] === row.id }"
                                                style="transition: transform 150ms"
                                            >▸</span>
                                        </td>
                                        <td class="px-2 py-1 truncate max-w-[220px]" :title="row.name">
                                            {{ row.name }}
                                            <span
                                                v-if="isRowDecided(cat.key, row.id)"
                                                class="text-emerald-400 text-[10px] ml-1"
                                                title="Decision saved this session"
                                            >✓</span>
                                        </td>
                                        <td class="px-2 py-1 text-gray-500">{{ row.iso_code }}</td>
                                        <td class="px-2 py-1 text-gray-500">{{ admLabel(row.adm_level) }}</td>
                                        <td class="px-2 py-1 text-right" v-if="cat.key === 'aggregation_discrepancies'">
                                            {{ fmtInt(row.delta) }}
                                        </td>
                                        <td
                                            class="px-2 py-1 text-right"
                                            :class="(row.delta_pct ?? 0) < 0 ? 'text-red-400' : 'text-amber-300'"
                                            v-if="cat.key === 'aggregation_discrepancies'"
                                        >
                                            {{ row.delta_pct }}%
                                        </td>
                                        <td class="px-2 py-1 text-right text-gray-400" v-if="cat.key !== 'aggregation_discrepancies'">
                                            {{ row.population != null ? fmtInt(row.population) : '—' }}
                                        </td>
                                        <td class="px-2 py-1 text-right text-gray-500" v-if="cat.key !== 'aggregation_discrepancies'">
                                            {{ row.area_km2 != null ? fmtInt(row.area_km2) : '—' }}
                                        </td>
                                    </tr>
                                    <tr
                                        v-if="expandedRow[cat.key] === row.id"
                                        :key="'detail-' + row.id"
                                    >
                                        <td
                                            :colspan="cat.key === 'aggregation_discrepancies' ? 6 : 6"
                                            class="px-2 py-2 bg-gray-950"
                                        >
                                            <RowDetailPanel
                                                :category="cat.key"
                                                :jurisdiction-id="row.id"
                                                @saved="onRowDecisionSaved(cat, row.id)"
                                            />
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination + actions -->
                    <div class="flex items-center justify-between gap-3 pt-1">
                        <div class="text-[10px] font-mono text-gray-500">
                            <template v-if="fetchedRows[cat.key]?.length">
                                Showing {{ fetchedRows[cat.key].length }} of {{ fmtInt(totals[cat.key]) }}
                            </template>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="nextOffsets[cat.key] != null"
                                type="button"
                                @click="loadRows(cat, true)"
                                :disabled="loading[cat.key]"
                                class="text-xs px-3 py-1 rounded border bg-gray-900 border-gray-700 text-gray-200 hover:bg-gray-800 disabled:opacity-50"
                            >
                                Load 50 more
                            </button>
                            <button
                                type="button"
                                @click="acknowledge(cat)"
                                class="text-xs px-3 py-1 rounded border bg-emerald-900/30 border-emerald-800 text-emerald-200 hover:bg-emerald-900/60"
                                title="Collapse this card and mark it as reviewed for this session. Persistence lands in Phase J."
                            >
                                Mark reviewed
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
