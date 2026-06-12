<template>
    <!-- flex-1 instead of an explicit calc(100vh - 49px). The hardcoded
         49px assumed only the top nav existed; if a SchemaUpdateBanner
         (or any other element) renders above us, the explicit height
         overflows and the top nav can scroll out of view. flex-1 lets
         AppShell's main--flush flex column do the sizing, which is correct
         regardless of how many siblings render above us. -->
    <div class="flex flex-1 overflow-hidden min-h-0">

            <!-- Left panel: metadata -->
            <aside class="w-80 shrink-0 bg-gray-900 border-r border-gray-800 flex flex-col overflow-y-auto">

                <!-- Breadcrumb. Every entry — including the first (planet
                     root) — links to that jurisdiction's own map page via
                     its slug. Previously the first entry hardcoded
                     `/jurisdictions` (the table-list index), which made
                     clicking "Earth" leave the map view entirely. -->
                <div class="px-4 py-3 border-b border-gray-800 text-xs text-gray-400 flex flex-wrap gap-1 items-center">
                    <template v-if="jurisdiction.ancestors.length > 0">
                        <a :href="`/jurisdictions/${jurisdiction.ancestors[0].slug}`" class="hover:text-white transition-colors">
                            {{ jurisdiction.ancestors[0].name }}
                        </a>
                        <template v-for="ancestor in jurisdiction.ancestors.slice(1)" :key="ancestor.id">
                            <span class="text-gray-600">›</span>
                            <a :href="`/jurisdictions/${ancestor.slug}`" class="hover:text-white transition-colors">
                                {{ ancestor.name }}
                            </a>
                        </template>
                    </template>
                    <template v-else>
                        <!-- On Earth itself: "World" is the implicit root,
                             no clickable parent above. Render as plain text. -->
                        <span class="text-gray-500">World</span>
                    </template>
                    <span class="text-gray-600">›</span>
                    <span class="text-gray-200">{{ jurisdiction.name }}</span>
                </div>

                <!-- Main info — compact header (District Mapper pattern):
                     name + adm-label + inline population/members in one block,
                     no separate stat cards. Frees vertical space for the
                     review-issues panel and the map-data-review overlay. -->
                <div class="p-4 flex flex-col gap-4">
                    <div>
                        <span class="inline-block text-xs font-medium px-2 py-0.5 rounded-full bg-blue-900 text-blue-300 mb-2">
                            {{ jurisdiction.adm_label }}
                        </span>
                        <h1 class="text-xl font-bold text-white leading-tight">{{ jurisdiction.name }}</h1>
                        <p v-if="jurisdiction.iso_code" class="text-sm text-gray-400 mt-0.5">{{ jurisdiction.iso_code }}</p>

                        <!-- Inline stats line. Colors match the map-control
                             toggle buttons and pull from the Wong colorblind-
                             safe palette (the same set the District Mapper
                             uses): #E69F00 orange = Population,
                             #56B4E9 sky-blue = Members. -->
                        <div class="flex items-baseline gap-4 mt-3">
                            <div>
                                <span class="text-lg font-semibold tabular-nums" style="color: #E69F00">{{ formatPop(jurisdiction.population) }}</span>
                                <span class="text-xs text-gray-500 ml-1">population</span>
                                <span v-if="jurisdiction.population_year" class="text-[10px] text-gray-600 ml-1">
                                    ({{ jurisdiction.population_year }})
                                </span>
                            </div>
                            <div>
                                <span class="text-lg font-semibold tabular-nums" style="color: #56B4E9">{{ childCount.toLocaleString() }}</span>
                                <span class="text-xs text-gray-500 ml-1">members</span>
                            </div>
                        </div>
                    </div>

                    <!-- P.6 — Review-issue badges. Lights up when this row
                         shows up in any DataReviewService category so the
                         operator can audit specific jurisdictions during
                         the acceptance pass. -->
                    <div v-if="hasAnyReviewBadge" class="bg-gray-800 rounded-lg p-3 space-y-1.5">
                        <div class="text-xs text-gray-400 mb-1">Review issues</div>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-if="review.is_orphan"
                                  class="px-2 py-0.5 rounded text-xs bg-red-900 text-red-200 border border-red-700">
                                orphan
                            </span>
                            <span v-if="review.is_population_gap"
                                  class="px-2 py-0.5 rounded text-xs bg-amber-900 text-amber-200 border border-amber-700">
                                no population
                            </span>
                            <span v-if="review.is_aggregation_discrepancy"
                                  class="px-2 py-0.5 rounded text-xs bg-amber-900 text-amber-200 border border-amber-700"
                                  :title="`Children sum diverges by ${review.rollup_delta_pct}% from this row's population`">
                                pop discrepancy
                            </span>
                            <span v-if="review.is_sovereign_territory"
                                  class="px-2 py-0.5 rounded text-xs bg-blue-900 text-blue-200 border border-blue-700">
                                territory
                            </span>
                            <span v-if="review.parent_iso_differs"
                                  class="px-2 py-0.5 rounded text-xs bg-emerald-900 text-emerald-200 border border-emerald-700"
                                  :title="`Parent assigned via ${review.parent_assigned_via}`">
                                cross-iso parent
                            </span>
                        </div>
                        <div v-if="review.parent_assigned_via && !review.parent_iso_differs" class="text-[11px] text-gray-500 mt-1">
                            parent assigned via
                            <span class="font-mono text-gray-400">{{ review.parent_assigned_via }}</span>
                        </div>
                        <div v-if="directChildOrphans > 0" class="text-[11px] text-amber-400 mt-1">
                            {{ directChildOrphans }} direct child{{ directChildOrphans === 1 ? '' : 'ren' }}
                            with zero population
                        </div>
                    </div>

                    <!-- Region & dataset metadata. Absorbs what used to be
                         three separate cards (geographic context, official
                         languages, data source) — they're all dataset-level
                         iso facts and read more naturally as one block.
                         The card always renders for non-planet rows; planet
                         (no iso meta) hides the meta-derived lines but still
                         shows the data-source line. -->
                    <div v-if="meta || jurisdiction.adm_level > 0 || jurisdiction.source"
                         class="bg-gray-800 rounded-lg p-3 space-y-1.5">
                        <div class="text-xs text-gray-400 mb-1">Region &amp; dataset</div>
                        <div v-if="meta?.boundary_canonical && meta.boundary_canonical !== jurisdiction.name"
                             class="text-xs text-gray-300 italic">
                            {{ meta.boundary_canonical }}
                        </div>
                        <div v-if="meta?.continent" class="text-xs">
                            <span class="text-gray-500">Continent:</span>
                            <span class="text-gray-200 ml-1">{{ meta.continent }}</span>
                        </div>
                        <div v-if="meta?.unsdg_region" class="text-xs">
                            <span class="text-gray-500">UNSDG region:</span>
                            <span class="text-gray-200 ml-1">{{ meta.unsdg_region }}</span>
                        </div>
                        <div v-if="meta?.unsdg_subregion" class="text-xs">
                            <span class="text-gray-500">Subregion:</span>
                            <span class="text-gray-200 ml-1">{{ meta.unsdg_subregion }}</span>
                        </div>
                        <div v-if="meta?.world_bank_income_group" class="text-xs">
                            <span class="text-gray-500">Income group:</span>
                            <span class="text-gray-200 ml-1">{{ meta.world_bank_income_group }}</span>
                        </div>
                        <!-- Official languages (only meaningful for non-planet rows;
                             Earth's seeded ['en'] gets hidden by the adm_level gate). -->
                        <div v-if="jurisdiction.adm_level > 0 && jurisdiction.official_languages?.length"
                             class="text-xs flex items-baseline flex-wrap gap-1.5">
                            <span class="text-gray-500">Languages:</span>
                            <span
                                v-for="lang in jurisdiction.official_languages"
                                :key="lang"
                                class="inline-block text-[10px] font-mono font-medium px-1.5 py-0 rounded bg-gray-700 text-gray-200 uppercase"
                            >{{ lang }}</span>
                        </div>
                        <!-- Data source — implied detail; small caption-style. -->
                        <div v-if="jurisdiction.source" class="text-[10px] text-gray-500 mt-1">
                            Source: <span class="text-gray-400 capitalize">{{ jurisdiction.source.replace(/_/g, ' ') }}</span>
                            <span v-if="meta?.year_represented"> · geoBoundaries year {{ meta.year_represented }}</span>
                        </div>
                    </div>

                    <!-- WI-9 — Activation status line (WF-JUR-01 bootstrap
                         tracker). Reads jurisdiction_activations: no row =
                         dormant boundary; planet root special-cased as
                         founded-at-setup. Styled like the review-issue
                         badges above. -->
                    <div class="bg-gray-800 rounded-lg p-3">
                        <div class="text-xs text-gray-400 mb-1.5">Activation</div>
                        <div class="flex items-baseline flex-wrap gap-1.5">
                            <span class="px-2 py-0.5 rounded text-xs" :class="activationDisplay.chip">
                                {{ activationDisplay.label }}
                            </span>
                            <span v-if="activationDisplay.detail" class="text-[11px] text-gray-400">
                                {{ activationDisplay.detail }}
                            </span>
                        </div>
                    </div>

                    <!-- Legislature & Districts link — kept (separate concern from
                         the new viewer's stats panel; legislature browser still
                         owns type_a/b display). -->
                    <!-- P.6.x.3 — Legislature button state machine.
                         Three exclusive states per jurisdiction:
                           1. Pre-apportionment: no button (Earth's Accept
                              Map Data card below handles the planet case).
                           2. Apportioned + no district map: "Create first
                              district map" — drops the operator into the
                              legislature view's `+ new map` flow.
                           3. Apportioned + has district map: existing
                              "View Legislature & Districts" link.
                         The legislature_id only exists post-apportionment
                         (ApportionmentSeedCommand creates one per parent
                         jurisdiction with children), so `v-if="legislature_id"`
                         naturally gates state 1 → states 2/3. -->
                    <template v-if="legislature_id">
                        <!-- This jurisdiction IS the legislature's root, so its
                             own slug is the legislature's canonical address. -->
                        <a v-if="has_district_map"
                           :href="`/legislatures/${jurisdiction.slug}`"
                           class="block w-full text-center text-xs font-medium px-3 py-2 rounded
                                  bg-emerald-800 hover:bg-emerald-700 text-emerald-100 transition-colors">
                            View Legislature &amp; Districts →
                        </a>
                        <a v-else
                           :href="`/legislatures/${jurisdiction.slug}`"
                           class="block w-full text-center text-xs font-medium px-3 py-2 rounded
                                  bg-violet-800 hover:bg-violet-700 text-violet-100 transition-colors">
                            Create first district map →
                        </a>
                    </template>

                    <!-- P.6 — Acceptance gate. Visible only at planet scope.
                         Disabled until the ETL has finished and the operator
                         hasn't already accepted. Click stamps map_accepted_at
                         and dispatches apportionment:seed via Horizon. -->
                    <div v-if="map_acceptance.is_planet_scope" class="border-t border-gray-700 pt-3 mt-2">
                        <div v-if="map_acceptance.map_accepted_at"
                             class="bg-emerald-900/40 border border-emerald-700 rounded-lg p-3 text-emerald-200">
                            <div class="text-xs uppercase tracking-wider mb-1">Maps accepted</div>
                            <div class="text-sm">{{ formatTime(map_acceptance.map_accepted_at) }}</div>
                            <div v-if="map_acceptance.apportionment_completed_at" class="text-xs text-emerald-300 mt-1">
                                Apportionment completed {{ formatTime(map_acceptance.apportionment_completed_at) }}
                            </div>
                            <div v-else class="text-xs text-emerald-300 mt-1 italic">
                                Apportionment running…
                            </div>
                        </div>
                        <button v-else
                                type="button"
                                @click="acceptMaps"
                                :disabled="acceptingMaps"
                                class="block w-full text-center text-sm font-semibold px-3 py-2.5 rounded
                                       bg-blue-700 hover:bg-blue-600 disabled:bg-gray-700 disabled:cursor-not-allowed
                                       text-white transition-colors">
                            {{ acceptingMaps ? 'Accepting…' : 'Accept Map Data &amp; Continue →' }}
                        </button>
                        <div v-if="acceptError" class="mt-2 text-xs text-red-400">
                            {{ acceptError }}
                        </div>
                    </div>
                </div>

                <!-- Hovered context feature -->
                <div v-if="hoveredFeature" class="mx-4 mb-4 p-3 bg-blue-900/40 border border-blue-700 rounded-lg">
                    <div class="text-xs text-blue-300 mb-1">
                        {{ hoveredFeature.depth === 0 ? 'Adjacent' : hoveredFeature.depth === 1 ? 'Parent region' : 'Wider region' }}
                    </div>
                    <div class="text-sm font-semibold text-white">{{ hoveredFeature.name }}</div>
                    <div class="text-xs text-gray-300 mt-1">
                        Population: {{ hoveredFeature.population.toLocaleString() }}
                    </div>
                    <div v-if="hoveredFeature.child_count > 0" class="text-xs text-gray-400">
                        {{ hoveredFeature.child_count }} members
                    </div>
                    <div v-else class="text-xs text-gray-500 italic">No further sub-divisions</div>
                </div>

                <!-- Hovered child -->
                <div v-if="hoveredChild" class="mx-4 mb-4 p-3 bg-green-900/40 border border-green-700 rounded-lg">
                    <div class="text-xs text-green-300 mb-1">Hovering</div>
                    <div class="text-sm font-semibold text-white">{{ hoveredChild.name }}</div>
                    <div class="text-xs text-gray-300 mt-1">
                        Population: {{ hoveredChild.population.toLocaleString() }}
                    </div>
                    <div v-if="hoveredChild.child_count > 0" class="text-xs text-gray-400">
                        {{ hoveredChild.child_count }} members
                    </div>
                    <div v-else class="text-xs text-gray-500 italic">No further sub-divisions</div>
                </div>

                <!-- No children notice -->
                <div v-if="!hasChildren" class="mx-4 mb-4 p-3 bg-gray-800 rounded-lg text-sm text-gray-400 italic">
                    This is the most local jurisdiction level available.
                </div>
            </aside>

            <!-- Right panel: map -->
            <div class="flex-1 relative">
                <div v-if="loading" class="absolute inset-0 z-[1000] flex items-center justify-center bg-gray-950/70">
                    <div class="text-white text-lg font-medium">Loading map…</div>
                </div>
                <div id="jurisdiction-map" class="w-full h-full"></div>

                <!-- Loading-raster banner — visible while WorldPop tiles
                     fetch on a cold-cache zoom/pan. Wired to the
                     TileLayer's `loading`/`load` events; auto-hides when
                     the visible viewport is fully cached. -->
                <div v-if="rasterLoading"
                     class="absolute top-3 left-1/2 -translate-x-1/2 z-[1001]
                            px-3 py-1.5 rounded-full
                            bg-indigo-900/85 border border-indigo-600 text-indigo-100
                            text-xs font-medium shadow-lg
                            flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-indigo-300 animate-pulse"></span>
                    Loading population raster…
                </div>

                <!-- P.6.x.2 — Map controls stack. Mirrors the District Mapper's
                     pattern (Legislature/Show.vue) so the two viewers feel
                     consistent: top-right column, identical button styling,
                     localStorage-persisted state that survives drill-down. -->
                <div class="absolute top-3 right-3 z-[1001] flex flex-col gap-1">
                    <button
                        type="button"
                        @click="showNames = !showNames"
                        :title="'Toggle jurisdiction name labels'"
                        class="px-2 py-1 rounded text-xs border transition-colors select-none"
                        :class="showNames
                            ? 'bg-violet-700 border-violet-500 text-white'
                            : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                    >Names</button>
                    <button
                        type="button"
                        @click="showPop = !showPop"
                        :title="'Toggle population number under each name'"
                        class="px-2 py-1 rounded text-xs border transition-colors select-none"
                        :class="showPop
                            ? 'border-transparent text-white'
                            : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                        :style="showPop ? { backgroundColor: '#E69F00', borderColor: '#E69F00' } : null"
                    >Population</button>
                    <button
                        type="button"
                        @click="showMembers = !showMembers"
                        :title="'Toggle direct-child count (“members”) under each name'"
                        class="px-2 py-1 rounded text-xs border transition-colors select-none"
                        :class="showMembers
                            ? 'border-transparent text-white'
                            : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                        :style="showMembers ? { backgroundColor: '#56B4E9', borderColor: '#56B4E9' } : null"
                    >Members</button>
                    <button
                        type="button"
                        @click="showRaster = !showRaster"
                        :title="'Toggle WorldPop population density raster overlay'"
                        class="px-2 py-1 rounded text-xs border transition-colors select-none"
                        :class="showRaster
                            ? 'bg-indigo-700 border-indigo-500 text-white'
                            : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                    >Raster</button>
                </div>
            </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

// Map tool: full chrome + flush main (the Leaflet sizing contract — flex
// column, no padding, overflow hidden; panes manage their own scroll).
defineOptions({
    layout: (h, page) => h(AppShell, { variant: 'flush' }, () => page),
})

const props = defineProps({
    jurisdiction:        Object,
    ancestors:           Array,
    childCount:          Number,
    hasChildren:         Boolean,
    directChildOrphans:  { type: Number, default: 0 },
    // P.6 Inertia props
    meta:                { type: Object, default: null },
    review:              { type: Object, default: () => ({}) },
    map_acceptance:      { type: Object, default: () => ({ is_planet_scope: false }) },
    legislature_id:      String,
    has_district_map:    { type: Boolean, default: false },
    // WI-9 — WF-JUR-01 bootstrap-tracker row { state, critical_population_at,
    // activated_at } or null (= dormant boundary).
    activation:          { type: Object, default: null },
})

props.jurisdiction.ancestors = props.ancestors

const loading        = ref(true)
const hoveredFeature = ref(null)
const hoveredChild   = ref(null)

// P.6 — review-badges + accept-maps state
const acceptingMaps = ref(false)
const acceptError   = ref('')

// P.6.x.2 — toggle state with localStorage persistence (mimics the
// District Mapper pattern from Legislature/Show.vue). Global keys (no scope
// id) so state survives every drill-down: Earth → USA → California keeps
// the toggles intact.
const LS = {
    names:   'jur_label_names',
    pop:     'jur_label_pop',
    members: 'jur_label_members',     // renamed from 'sub' to match District Mapper terminology
    raster:  'jur_overlay_raster',
}
const showNames   = ref(localStorage.getItem(LS.names)   === '1')
const showPop     = ref(localStorage.getItem(LS.pop)     === '1')
const showMembers = ref(localStorage.getItem(LS.members) === '1')
const showRaster  = ref(localStorage.getItem(LS.raster)  === '1')

watch(showNames,   v => localStorage.setItem(LS.names,   v ? '1' : '0'))
watch(showPop,     v => localStorage.setItem(LS.pop,     v ? '1' : '0'))
watch(showMembers, v => localStorage.setItem(LS.members, v ? '1' : '0'))
watch(showRaster,  v => localStorage.setItem(LS.raster,  v ? '1' : '0'))

// Loading-raster overlay state. Wired to the TileLayer's `loading`/`load`
// events inside onMounted so the operator sees a small banner while
// out-of-cache tiles generate (~500 ms-2 s each cold). Hidden when all
// requested tiles in the viewport have loaded.
const rasterLoading = ref(false)

// Pretty-print population numbers the same way the District Mapper does:
// 7.99B / 245.0M / 12K / 369 — matches the nameplate density at a glance.
function formatPop(n) {
    if (n == null) return ''
    if (n >= 1_000_000_000) return (n / 1_000_000_000).toFixed(1) + 'B'
    if (n >= 1_000_000)     return (n / 1_000_000).toFixed(1) + 'M'
    if (n >= 1_000)         return (n / 1_000).toFixed(0) + 'K'
    return n.toLocaleString()
}

const hasAnyReviewBadge = computed(() => {
    const r = props.review || {}
    return r.is_orphan
        || r.is_population_gap
        || r.is_aggregation_discrepancy
        || r.is_sovereign_territory
        || (r.parent_iso_differs && r.parent_assigned_via)
})

function formatTime(iso) {
    if (!iso) return ''
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return iso
        return d.toLocaleString()
    } catch (e) {
        return iso
    }
}

// WI-9 — activation status line (WF-JUR-01). Maps the bootstrap-tracker
// state onto a badge + caption. No activation row = dormant boundary,
// EXCEPT the planet root whose legislature is founded by the setup wizard
// (the activation engine never files a row for it).
const activationDisplay = computed(() => {
    const a = props.activation
    if (a?.state === 'self_governing') {
        return {
            label:  'Self-governing',
            chip:   'bg-emerald-900 text-emerald-200 border border-emerald-700',
            detail: a.activated_at ? `since ${formatTime(a.activated_at)}` : '',
        }
    }
    if (a?.state === 'bootstrapping') {
        return {
            label:  'Bootstrapping',
            chip:   'bg-violet-900 text-violet-200 border border-violet-700',
            detail: 'institutions being seated',
        }
    }
    if (a?.state === 'critical_population') {
        return {
            label:  'Critical population',
            chip:   'bg-amber-900 text-amber-200 border border-amber-700',
            detail: a.critical_population_at ? `reached ${formatTime(a.critical_population_at)}` : 'reached',
        }
    }
    // boundary_loaded row, or no row at all.
    if (!a && props.jurisdiction.adm_level === 0 && props.legislature_id) {
        return {
            label:  'Self-governing',
            chip:   'bg-emerald-900 text-emerald-200 border border-emerald-700',
            detail: 'founded at instance setup',
        }
    }
    return {
        label:  'Dormant',
        chip:   'bg-gray-700 text-gray-300 border border-gray-600',
        detail: 'activates at critical population',
    }
})

async function acceptMaps() {
    if (acceptingMaps.value) return
    acceptingMaps.value = true
    acceptError.value   = ''
    try {
        const res = await fetch('/api/jurisdictions/accept-maps', {
            method:      'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            // WI-9: apportionment scope = the jurisdiction whose maps are
            // being accepted (the button only renders at planet scope today,
            // so this is the planet root — but the endpoint no longer
            // assumes it).
            body: JSON.stringify({ jurisdiction_id: props.jurisdiction.id }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            acceptError.value = data.error || `accept failed (HTTP ${res.status})`
            return
        }
        // Reload so the page reflects the persisted map_accepted_at + the
        // apportionment-running banner. Server provides the canonical state.
        router.reload({ only: ['map_acceptance'] })
    } catch (e) {
        acceptError.value = String(e?.message || e)
    } finally {
        acceptingMaps.value = false
    }
}

// ── Map styles ────────────────────────────────────────────────────────────────
// Transparency strategy (operator review, post-protomaps):
//   - Self polygon: outline only — basemap + raster show through clearly.
//   - Children (interactive): low fill (~0.08-0.10), borders prominent.
//     Hover bumps fill to ~0.30 so the active selection pops.
//   - Ancestors / siblings: progressively DARKER as you move OUT from
//     the active scope. depth=0 (immediate siblings) is barely tinted so
//     they read like "almost the same level as me"; depth=2 (grandparent's
//     siblings, far away) is most opaque so the world fades away from
//     the active jurisdiction. This is the reverse of the original
//     ordering which had depth=0 darkest — the inverted gradient now
//     directs the eye inward to the current scope.
// Result: the WorldPop raster, when toggled on, is unobscured inside the
// current jurisdiction and dims further outside as context distance grows.
const contextStyles = [
    { fillColor: '#94a3b8', fillOpacity: 0.12, color: '#64748b', weight: 0.5 },   // depth 0 — immediate siblings, lightest
    { fillColor: '#64748b', fillOpacity: 0.18, color: '#475569', weight: 0.5 },   // depth 1 — parent's siblings
    { fillColor: '#475569', fillOpacity: 0.25, color: '#334155', weight: 1 },     // depth 2 — far context, darkest
]
const contextHoverStyles = [
    { fillColor: '#e2e8f0', fillOpacity: 0.22, color: '#64748b', weight: 0.8 },
    { fillColor: '#cbd5e1', fillOpacity: 0.30, color: '#475569', weight: 1 },
    { fillColor: '#94a3b8', fillOpacity: 0.40, color: '#334155', weight: 1.5 },
]
const contextLabelClasses = ['jl-d0', 'jl-d1', 'jl-d2']

const childStyle       = { fillColor: '#4a7c59', fillOpacity: 0.10, color: '#2d4a35', weight: 1 }
const childHoverStyle  = { fillColor: '#6aad80', fillOpacity: 0.35, color: '#1a2e1f', weight: 2 }
const selfOutlineStyle = { fillOpacity: 0, color: '#94a3b8', weight: 2, dashArray: '5,5' }
const leafOutlineStyle = { fillColor: '#4a7c59', fillOpacity: 0.15, color: '#2d4a35', weight: 2 }

// Map ref hoisted to module scope so the raster-overlay watcher can mutate
// layers after onMounted. Initialised inside onMounted; layers added after.
let mapInstance = null
let rasterLayer = null   // L.TileLayer — see P.6.x.1

function applyRasterOverlay() {
    if (!mapInstance) return
    if (showRaster.value) {
        if (!rasterLayer) {
            // Tile coords are EPSG:3857 (Web Mercator) which is what the
            // server's RasterTileController generates and what Leaflet's
            // TileLayer expects by default. No per-jurisdiction URL — same
            // layer serves every scope, naturally including Earth.
            rasterLayer = L.tileLayer('/api/rasters/{z}/{x}/{y}.png', {
                minZoom: 0,
                maxZoom: 12,
                opacity: 0.7,
                tms: false,
                attribution: 'Population &copy; <a href="https://www.worldpop.org/" target="_blank" rel="noopener">WorldPop</a>',
                // Prevent the "doubled opacity" flicker during zoom: don't
                // hold stretched parent-zoom tiles while new tiles load. The
                // semi-transparent overlay stacks visually when old + new
                // coexist, so we let the layer go blank for the zoom
                // duration and refill once the zoom settles.
                keepBuffer:        0,
                updateWhenZooming: false,
                updateWhenIdle:    true,
            })
            // Loading-banner wiring: TileLayer emits 'loading' when the first
            // tile starts fetching for a given viewport, and 'load' when all
            // currently-requested tiles have arrived. Bind to the
            // rasterLoading ref so the template banner shows/hides.
            rasterLayer.on('loading', () => { rasterLoading.value = true })
            rasterLayer.on('load',    () => { rasterLoading.value = false })
        }
        if (!mapInstance.hasLayer(rasterLayer)) {
            rasterLayer.addTo(mapInstance)
            // Default tilePane (z=200) sits above the protomaps basemapPane
            // (z=150) and below the polygon overlayPane (z=400) — exactly
            // where the population overlay belongs. No bringToBack/Front
            // calls needed; the pane stack handles ordering.
        }
    } else if (rasterLayer && mapInstance.hasLayer(rasterLayer)) {
        mapInstance.removeLayer(rasterLayer)
        rasterLoading.value = false   // hide banner when toggled off mid-load
    }
}

watch(showRaster, () => applyRasterOverlay())

onMounted(async () => {
    // Dynamic minZoom: prevent the operator from zooming out further than
    // the point at which the world fills the viewport vertically. Below
    // that, Leaflet would render empty grey space above/below the world
    // copy, which the ±360° wrap clones can't fix because they're
    // longitudinal. We compute the floor zoom based on container height:
    //   World pixel height at zoom z = 256 × 2^z
    //   Solve 256 × 2^z = container height → z = log2(h / 256)
    const mapEl    = document.getElementById('jurisdiction-map')
    const mapH     = (mapEl?.clientHeight) || 700
    const dynamicMinZoom = Math.max(0, Math.ceil(Math.log2(mapH / 256)))

    const map = L.map('jurisdiction-map', {
        zoomControl:    true,
        // Infinite east-west panning: when the user crosses the antimeridian
        // the view snaps to the equivalent point on the original copy of the
        // world. Combined with the ±360° polygon clones added below this
        // gives the same wraparound behavior as the raster TileLayer.
        worldCopyJump:  true,
        minZoom:        dynamicMinZoom,
        // Vertical lock: prevent panning past the world's lat extent so
        // blank grey space never appears above the north pole or below
        // the south pole. The longitude range is intentionally wide
        // (±540° = 1.5 world widths each direction) so worldCopyJump's
        // antimeridian wrap continues to work — only the latitude is
        // actually constrained. 85.05° is the standard Web Mercator
        // upper-bound (the projection diverges at the poles).
        maxBounds:          [[-85.05, -540], [85.05, 540]],
        maxBoundsViscosity: 1.0,
    })
    mapInstance = map

    // Attribution: TileLayer / Protomaps each contribute their own strings
    // automatically via Leaflet's attribution control. The GeoJSON polygon
    // layer doesn't, so we register the boundary-data attribution explicitly
    // here once. Mirrors the WorldPop attribution on the raster TileLayer
    // below — every dataset surfacing on the map gets credit.
    //
    // Also drop the Ukrainian-flag emoji that Leaflet ships in its default
    // prefix — we want the attribution control to credit the library
    // without making a geopolitical statement. We keep "Leaflet" itself
    // linked as the project's open-source norm requires.
    map.attributionControl.setPrefix(
        '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>'
    )
    map.attributionControl.addAttribution(
        'Boundaries &copy; <a href="https://www.geoboundaries.org/" target="_blank" rel="noopener">geoBoundaries</a>'
    )

    // Explicit layer panes to guarantee z-order regardless of layer-add
    // sequencing. Leaflet's default tilePane (z=200) hosts the WorldPop
    // raster TileLayer; the protomaps basemap goes in a custom pane below
    // it (z=150) so the population overlay sits ABOVE the cartography. The
    // polygon overlayPane (z=400) sits above both as usual.
    map.createPane('basemapPane')
    map.getPane('basemapPane').style.zIndex = 150

    // Belt-and-suspenders for the sticky hover: if the cursor leaves the map
    // entirely (off the page or onto a sidebar element), Leaflet doesn't
    // always fire per-feature mouseout — explicitly clear any lingering
    // highlight here.
    map.on('mouseout', () => {
        if (_currentHover) _clearCurrentHover()
        hoveredFeature.value = null
        hoveredChild.value   = null
    })

    // Protomaps PMTiles base tiles. Lookup order, picked the first to match:
    //
    //   1. Latest dated bundle in the operator's protomaps directory
    //      (bind-mounted at /var/www/html/public/maps/protomaps via
    //      docker-compose.yml, defaulting to
    //      D:\fair-constitution-map-files\protomaps_pmtiles). Resolved by
    //      GET /api/maps/latest-pmtiles which scans the folder and returns
    //      the lexicographically-latest *.pmtiles filename — YYYYMMDD names
    //      naturally sort to date-order, so dropping a new dated bundle
    //      auto-replaces the active one on next page load.
    //
    //   2. Legacy single-file location at /maps/world.pmtiles — kept for
    //      backward compat with operators who haven't moved to the dated
    //      directory pattern yet.
    //
    //   3. Remote URL via VITE_PROTOMAPS_URL env — e.g. the public planet
    //      demo bucket (https://demo-bucket.protomaps.com/v4.pmtiles).
    //      Streamed via HTTP range requests so the browser only downloads
    //      the visible tiles (~hundreds of KB per session) regardless of
    //      the bundle's full size.
    //
    //   4. Nothing configured → polygon-only "ocean blue" rendering.
    try {
        const protomaps = await import('protomaps-leaflet')
        // namedFlavor isn't re-exported from protomaps-leaflet — it lives in
        // the sibling @protomaps/basemaps package (transitive dep). Importing
        // it here gives us the Flavor object that paintRules / labelRules
        // need; without it the basemap layer silently fails to initialise.
        const basemaps = await import('@protomaps/basemaps')
        let pmtilesUrl = null

        // 1. Dated-bundle directory via the backend scan.
        try {
            const res = await fetch('/api/maps/latest-pmtiles', { credentials: 'same-origin' })
            if (res.ok) {
                const data = await res.json()
                if (data?.url) pmtilesUrl = data.url
            }
        } catch (e) { /* fall through to legacy probe */ }

        // 2. Legacy fixed-filename probe.
        if (!pmtilesUrl) {
            try {
                const head = await fetch('/maps/world.pmtiles', { method: 'HEAD' })
                if (head.ok) pmtilesUrl = '/maps/world.pmtiles'
            } catch (e) { /* ignore network errors */ }
        }

        // 3. Build-time env URL.
        if (!pmtilesUrl) {
            const remote = import.meta.env?.VITE_PROTOMAPS_URL || ''
            if (remote) pmtilesUrl = remote
        }

        if (pmtilesUrl) {
            // Bilingual label rendering: local name (always) PLUS user's
            // browser-language translation when it differs. Protomaps v5
            // ships single-label rendering by default; we override the
            // places rules with FlexSymbolizer stacks so cities/regions/
            // countries render two lines — `東京` on top, `Tokyo` below
            // (when the bundle has `name:en` for that feature).
            const browserLang = (navigator.language || 'en').split('-')[0].toLowerCase()
            const flavor = basemaps.namedFlavor('light')

            // Build the base label rules using the LOCAL `name` field (no
            // lang suffix). That gives us a single-line render of the
            // local-language place name everywhere.
            const baseLabelRules = protomaps.labelRules(flavor, '')

            // Override `places` rules with FlexSymbolizer stacks. Other
            // data layers (roads / water / boundaries / etc.) keep the
            // default single-label rendering — bilingualising every road
            // sign would create label clutter at high zoom.
            const labelRules = baseLabelRules.map(rule => {
                if (rule.dataLayer !== 'places') return rule
                const orig = rule.symbolizer
                // Only wrap CenteredTextSymbolizer rules — GroupSymbolizer
                // wraps a circle + offset text and is harder to extend
                // safely. The low-zoom locality dots can stay default;
                // they're already terse.
                if (!(orig instanceof protomaps.CenteredTextSymbolizer)) return rule
                const sharedOpts = rule.__textOpts || {
                    // Reasonable defaults that match Protomaps' light flavor.
                    fill:   '#333',
                    stroke: '#fff',
                    width:  1,
                }
                const primary = new protomaps.CenteredTextSymbolizer({
                    ...sharedOpts,
                    labelProps: ['name'],
                    lineHeight: 1.5,
                    font: '500 12px sans-serif',
                })
                const secondary = new protomaps.CenteredTextSymbolizer({
                    ...sharedOpts,
                    // Only emit if name:lang exists AND differs from local name.
                    // Returning a non-existent prop name short-circuits the
                    // TextAttr.get loop to undefined → symbolizer renders
                    // nothing for that feature.
                    labelProps: (z, f) => {
                        const local = f.props.name
                        const trans = f.props['name:' + browserLang]
                        if (!trans || trans === local) return ['__skip__']
                        return ['name:' + browserLang]
                    },
                    font: '400 10px sans-serif',
                })
                return {
                    ...rule,
                    symbolizer: new protomaps.FlexSymbolizer([primary, secondary]),
                }
            })

            const layer = protomaps.leafletLayer({
                url:        pmtilesUrl,
                paintRules: protomaps.paintRules(flavor),
                labelRules: labelRules,
                lang:       browserLang,
                pane:       'basemapPane',
            })
            layer.addTo(map)
            console.info('Protomaps basemap loaded from', pmtilesUrl, '(lang:', browserLang + ', bilingual labels)')
        } else {
            console.info('Protomaps PMTiles not configured — using polygon-only map. '
                + 'Drop a .pmtiles file into D:\\fair-constitution-map-files\\protomaps_pmtiles, '
                + 'place one at public/maps/world.pmtiles, or set VITE_PROTOMAPS_URL in .env.')
        }
    } catch (e) {
        console.warn('Protomaps init failed, falling back to polygon-only map:', e)
    }

    // ── Label registry & redraw system ──────────────────────────────────────
    // P.6.x.2: nameplate-style labels matching the District Mapper. Built as
    // Leaflet divIcon markers wrapping the shared `.jurisdiction-name-label`
    // CSS class (defined in resources/css/app.css). Three toggles drive
    // visibility:
    //   showNames   = master switch (when off, no labels render at all)
    //   showPop     = include population number under the name
    //   showMembers = include direct-child count ("X members" — matches District
    //                 Mapper terminology where each sub-jurisdiction is a
    //                 member of its parent's legislature)
    // Each entry: { lat, lng, p (feature properties) }
    const labelRegistry = []
    let   activeMarkers = []

    function buildLabelHtml(p) {
        // Font weight scales with jurisdiction size — heavier = more important
        // (Earth/USA bold, county/township mid, neighbourhood light). Mirrors
        // the District Mapper's gravity-by-fractional-seats pattern, but
        // population is a stand-in here since the viewer isn't apportionment-
        // aware.
        const pop = Number(p.population) || 0
        const fw  = pop >= 1_000_000_000 ? 800
                  : pop >= 100_000_000   ? 700
                  : pop >=  10_000_000   ? 600
                  : pop >=   1_000_000   ? 500
                  : 400
        let html = `<div class="jurisdiction-name-label" style="font-weight:${fw}">`
                 + `${p.name}`
        if (showPop.value && p.population != null) {
            html += `<br><span class="jurisdiction-pop-label">${formatPop(p.population)}</span>`
        }
        if (showMembers.value && p.child_count != null && p.child_count > 0) {
            const sep = (showPop.value && p.population != null) ? ' · ' : '<br>'
            html += `<span class="jurisdiction-members-label">${sep}${p.child_count.toLocaleString()} members</span>`
        }
        html += `</div>`
        return html
    }

    function redrawLabels() {
        // Tear down all currently rendered label markers first.
        activeMarkers.forEach(m => map.removeLayer(m))
        activeMarkers = []
        // If Names is off, leave them down — clean polygon-only view.
        if (!showNames.value) return
        labelRegistry.forEach(({ lat, lng, p }) => {
            const m = L.marker([lat, lng], {
                icon: L.divIcon({
                    className:  '',
                    html:       buildLabelHtml(p),
                    iconSize:   null,
                    iconAnchor: [0, 0],
                }),
                interactive: false,
                keyboard:    false,
            }).addTo(map)
            activeMarkers.push(m)
        })
    }

    // Re-render whenever any of the three label toggles flips.
    watch([showNames, showPop, showMembers], redrawLabels)

    function registerLabel(lat, lng, p) {
        if (lat == null || lng == null) return
        labelRegistry.push({ lat, lng, p })
        if (!showNames.value) return
        const m = L.marker([lat, lng], {
            icon: L.divIcon({
                className:  '',
                html:       buildLabelHtml(p),
                iconSize:   null,
                iconAnchor: [0, 0],
            }),
            interactive: false,
            keyboard:    false,
        }).addTo(map)
        activeMarkers.push(m)
    }

    // ── Antimeridian wrap helpers ──────────────────────────────────────────
    // Leaflet wraps TileLayers across +/-180° longitude automatically, but
    // vector GeoJSON layers render once at their native coordinates. For
    // Earth-scope viewing centred over the Pacific or Americas, this leaves
    // big empty patches where Russia / NZ / Fiji should appear on the
    // wraparound. We address that by cloning each polygon layer at -360°
    // and +360° longitude and adding the clones as non-interactive
    // companions. Three copies (centre + two wraps) covers any viewport
    // a standard screen can show (max ~1.5× world width).
    function shiftCoords(coords, delta) {
        if (typeof coords[0] === 'number') return [coords[0] + delta, coords[1]]
        return coords.map(c => shiftCoords(c, delta))
    }
    function shiftedGeojson(geojson, delta) {
        if (!geojson || !geojson.features) return null
        return {
            type: 'FeatureCollection',
            features: geojson.features.map(f => ({
                ...f,
                geometry: f.geometry ? {
                    ...f.geometry,
                    coordinates: shiftCoords(f.geometry.coordinates, delta),
                } : null,
            })),
        }
    }
    function addAntimeridianWraps(geojson, style, onEachFeature = null) {
        // When onEachFeature is provided, wrap clones become interactive
        // with the same event handlers as the original — so a click or
        // hover on Russia's Chukotka peninsula appearing on the OPPOSITE
        // side of the map (via wrap clone) behaves identically to clicking
        // it on the native side. Without this the operator had to pan all
        // the way back to find a clickable copy of any antimeridian-
        // spanning jurisdiction.
        for (const dx of [-360, 360]) {
            const shifted = shiftedGeojson(geojson, dx)
            if (shifted && shifted.features.length > 0) {
                L.geoJSON(shifted, {
                    style,
                    interactive: onEachFeature !== null,
                    keyboard:    false,
                    onEachFeature: onEachFeature ?? undefined,
                }).addTo(map)
            }
        }
    }

    // ── Hover state tracker ─────────────────────────────────────────────────
    // Sticky-highlight fix + synchronized cross-clone hover. Each polygon
    // feature can exist as up to 3 visible copies on the map (the native
    // L.geoJSON layer + two ±360° wrap clones for antimeridian wrap).
    // Hovering ANY copy highlights ALL of them so Russia feels like one
    // jurisdiction even when it's rendered on both sides of the Pacific
    // viewport.
    //
    // Implementation: featureLayerRegistry maps p.slug → list of
    // { featureLayer, defaultStyle } entries (one per copy). On mouseover,
    // we look up by slug and setStyle(hoverStyle) on every matching
    // featureLayer; on mouseout we reset every one. The current-hover
    // state tracks which slug is active so we can clear it cleanly when
    // a new mouseover fires (the sticky-highlight fix from before).
    const featureLayerRegistry = new Map()
    function registerFeatureLayer(slug, featureLayer, defaultStyle) {
        if (!featureLayerRegistry.has(slug)) featureLayerRegistry.set(slug, [])
        featureLayerRegistry.get(slug).push({ featureLayer, defaultStyle })
    }
    function highlightFeatureBySlug(slug, hoverStyle) {
        const entries = featureLayerRegistry.get(slug) || []
        entries.forEach(({ featureLayer }) => {
            try { featureLayer.setStyle(hoverStyle) } catch (e) {}
        })
    }
    function resetFeatureBySlug(slug) {
        const entries = featureLayerRegistry.get(slug) || []
        entries.forEach(({ featureLayer, defaultStyle }) => {
            try { featureLayer.setStyle(defaultStyle) } catch (e) {}
        })
    }

    let _currentHover = null   // { slug } | null
    function _clearCurrentHover() {
        if (!_currentHover) return
        resetFeatureBySlug(_currentHover.slug)
        _currentHover = null
    }

    // ── Layer helpers ────────────────────────────────────────────────────────
    function addContextLayer(geojson, depth) {
        if (!geojson || geojson.features.length === 0) return

        const styleIdx   = Math.min(depth, contextStyles.length - 1)
        const style      = contextStyles[styleIdx]
        const hoverStyle = contextHoverStyles[styleIdx]
        const labelClass = contextLabelClasses[styleIdx]

        // Shared handler set — used by the native layer AND each wrap
        // clone. Highlight/reset target every copy via the slug registry
        // so hovering Russia's antimeridian-east wrap highlights the
        // native Russia at the same time.
        const bindFeature = (feature, featureLayer) => {
            const p = feature.properties
            registerFeatureLayer(p.slug, featureLayer, style)
            featureLayer.on('mouseover', () => {
                _clearCurrentHover()
                highlightFeatureBySlug(p.slug, hoverStyle)
                _currentHover = { slug: p.slug }
                hoveredFeature.value = { ...p, depth }
            })
            featureLayer.on('mouseout', () => {
                if (_currentHover && _currentHover.slug === p.slug) {
                    _clearCurrentHover()
                }
                if (hoveredFeature.value?.name === p.name) hoveredFeature.value = null
            })
            featureLayer.on('click', () => router.visit(`/jurisdictions/${p.slug}`))
        }

        L.geoJSON(geojson, { style, onEachFeature: bindFeature }).addTo(map)
        addAntimeridianWraps(geojson, style, bindFeature)

        // Register labels for the native centroid AND each wrap copy's
        // centroid (centroid_lat fixed, centroid_lng shifted by ±360)
        // so the Names toggle lights up the same jurisdiction's name on
        // every visible copy across the antimeridian.
        geojson.features.forEach(feature => {
            const p = feature.properties
            if (p.centroid_lat == null || p.centroid_lng == null) return
            registerLabel(p.centroid_lat, p.centroid_lng,           p, labelClass)
            registerLabel(p.centroid_lat, p.centroid_lng - 360,     p, labelClass)
            registerLabel(p.centroid_lat, p.centroid_lng + 360,     p, labelClass)
        })
    }

    // ── Data fetch & render ──────────────────────────────────────────────────
    const jurisdictionId  = props.jurisdiction.id
    const hasSiblings     = props.ancestors.length > 0
    const ancestorsToLoad = props.ancestors.filter(a => a.adm_level > 0)

    try {
        const [selfGeojson, siblingGeojson, ...ancestorGeojsons] = await Promise.all([
            fetch(`/api/jurisdictions/${jurisdictionId}/self.geojson`).then(r => r.json()),
            hasSiblings
                ? fetch(`/api/jurisdictions/${jurisdictionId}/siblings.geojson`).then(r => r.json())
                : Promise.resolve(null),
            ...ancestorsToLoad.map(a =>
                fetch(`/api/jurisdictions/${a.id}/siblings.geojson`).then(r => r.json())
            ),
        ])

        if (props.hasChildren) {
            const childGeojson = await fetch(`/api/jurisdictions/${jurisdictionId}/children.geojson`).then(r => r.json())

            ancestorsToLoad.forEach((ancestor, i) => {
                addContextLayer(ancestorGeojsons[i], ancestorsToLoad.length - i)
            })
            addContextLayer(siblingGeojson, 0)
            L.geoJSON(selfGeojson, { style: selfOutlineStyle }).addTo(map)
            addAntimeridianWraps(selfGeojson, selfOutlineStyle)

            // Shared handler set — every copy of a child polygon (native
            // + wrap clones) registers under the same slug so hovering
            // any one highlights all visible copies simultaneously.
            const bindChild = (feature, featureLayer) => {
                const p = feature.properties
                registerFeatureLayer(p.slug, featureLayer, childStyle)
                featureLayer.on('mouseover', () => {
                    _clearCurrentHover()
                    highlightFeatureBySlug(p.slug, childHoverStyle)
                    _currentHover = { slug: p.slug }
                    hoveredChild.value = p
                })
                featureLayer.on('mouseout', () => {
                    if (_currentHover && _currentHover.slug === p.slug) {
                        _clearCurrentHover()
                    }
                    if (hoveredChild.value?.name === p.name) hoveredChild.value = null
                })
                featureLayer.on('click', () => router.visit(`/jurisdictions/${p.slug}`))
            }

            const childLayer = L.geoJSON(childGeojson, {
                style: childStyle,
                onEachFeature: bindChild,
            }).addTo(map)
            addAntimeridianWraps(childGeojson, childStyle, bindChild)

            // Register labels for each visible copy (native + both wraps)
            // so jurisdiction names appear on whichever side of the
            // antimeridian the operator is viewing.
            childGeojson.features.forEach(feature => {
                const p = feature.properties
                if (p.centroid_lat == null || p.centroid_lng == null) return
                registerLabel(p.centroid_lat, p.centroid_lng,           p, 'jl-child')
                registerLabel(p.centroid_lat, p.centroid_lng - 360,     p, 'jl-child')
                registerLabel(p.centroid_lat, p.centroid_lng + 360,     p, 'jl-child')
            })

            const selfBounds = selfGeojson.features.length > 0
                ? L.geoJSON(selfGeojson).getBounds()
                : null
            const fitTarget = selfBounds || childLayer.getBounds()
            map.fitBounds(fitTarget, { padding: [40, 40] })

        } else {
            // Leaf: show context + self outline + self label
            ancestorsToLoad.forEach((ancestor, i) => {
                addContextLayer(ancestorGeojsons[i], ancestorsToLoad.length - i)
            })
            addContextLayer(siblingGeojson, 0)

            const selfLayer = L.geoJSON(selfGeojson, { style: leafOutlineStyle }).addTo(map)
            addAntimeridianWraps(selfGeojson, leafOutlineStyle)

            // Self label — works now that selfGeoJson includes centroid_lat/lng
            selfGeojson.features.forEach(feature => {
                const p = feature.properties
                registerLabel(p.centroid_lat, p.centroid_lng, p, 'jl-child')
            })

            if (selfGeojson.features.length > 0) {
                map.fitBounds(selfLayer.getBounds(), { padding: [40, 40] })
            }
        }

        // P.6.x.1: if the operator left the raster toggle on from a prior
        // session, restore the layer now that the map is ready.
        if (showRaster.value) applyRasterOverlay()

    } catch (e) {
        console.error('Failed to load jurisdiction GeoJSON', e)
    } finally {
        loading.value = false
    }
})
</script>

<style>
/* ── Ocean background ── */
#jurisdiction-map .leaflet-container {
    background: #a8c8e8 !important;
}

/*
 * Strip Leaflet's default tooltip chrome (white box, border, shadow).
 * All permanent map labels use className: 'jl-bare' on the tooltip wrapper.
 * Actual font/color styling lives on the <span> inside via jl-child / jl-d* classes.
 */
.leaflet-tooltip.jl-bare {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    border-radius: 0 !important;
    margin: 0 !important;
}
.leaflet-tooltip.jl-bare::before {
    display: none !important;
}

/* ── Children / self on leaf (green polygons — white text with shadow) ── */
.jl-child {
    font-weight: 700;
    font-size: 11px;
    color: white;
    text-shadow: 0 1px 3px rgba(0,0,0,0.9), 0 0 8px rgba(0,0,0,0.6);
    white-space: nowrap;
    pointer-events: none;
    line-height: 1.4;
    text-align: center;
}

/* ── Siblings / depth-0 (medium gray — dark text) ── */
.jl-d0 {
    font-weight: 600;
    font-size: 10px;
    color: #0f172a;
    white-space: nowrap;
    pointer-events: none;
    line-height: 1.4;
    text-align: center;
}

/* ── Uncle / depth-1 (light gray — dark italic) ── */
.jl-d1 {
    font-weight: 400;
    font-size: 9px;
    font-style: italic;
    color: #1e293b;
    white-space: nowrap;
    pointer-events: none;
    line-height: 1.4;
    text-align: center;
}

/* ── Granduncle / depth-2 (very light gray — muted italic) ── */
.jl-d2 {
    font-weight: 400;
    font-size: 8px;
    font-style: italic;
    color: #334155;
    white-space: nowrap;
    pointer-events: none;
    line-height: 1.4;
    text-align: center;
}
</style>
