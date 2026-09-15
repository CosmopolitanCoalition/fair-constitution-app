<template>
    <div class="flex flex-col flex-1 min-h-0 bg-gray-950 text-white">

            <!-- The harmonized activation surface (operator, 2026-08-08):
                 mode context + Activate All / Selected, integrated here so
                 Step 2's Continue lands somewhere that explains itself. -->
            <div v-if="isOperator && scale?.map_accepted_at"
                 class="flex items-center gap-3 px-6 py-2 bg-gray-900/80 border-b border-gray-800 shrink-0 text-xs">
                <span class="px-2 py-0.5 rounded-full bg-violet-900/60 text-violet-200 border border-violet-700">
                    {{ MODE_LABEL[scale.mode] ?? scale.mode }}
                </span>
                <span class="text-gray-400">
                    {{ scale.mode === 'eager'
                        ? t('c_jurisdictions.index.mode_help_eager', 'The full-scale build runs on its own; these controls are for reruns and spot work.')
                        : scale.mode === 'population'
                            ? t('c_jurisdictions.index.mode_help_population', 'Places boot automatically as verified residents cross their threshold — or activate them here ahead of demand.')
                            : scale?.is_sandbox
                            ? t('c_jurisdictions.index.mode_help_sandbox', 'Nothing is automatic: Activate rows here (or + children), draw maps — and Simulate populates an activated jurisdiction with simulated residents, orgs, and bills.')
                            : t('c_jurisdictions.index.mode_help_manual', 'Nothing is automatic: Activate rows here (or + children), draw maps, build institutions through their forms.') }}
                </span>
                <span class="ml-auto flex items-center gap-2">
                    <a href="/setup/step/2"
                       class="px-2.5 py-1 rounded border border-gray-600 text-gray-300 hover:bg-gray-800 transition-colors">
                        {{ t('c_jurisdictions.index.back_setup', '← Setup') }}
                    </a>
                    <button type="button" :disabled="advancingSetup" @click="continueSetup"
                            :title="t('c_jurisdictions.index.continue_title', 'Mark this step done and continue to review & confirm')"
                            class="px-2.5 py-1 rounded border border-blue-600 text-blue-200 hover:bg-blue-900/40 disabled:opacity-50 transition-colors">
                        {{ advancingSetup ? t('c_jurisdictions.index.advancing', 'Advancing…') : t('c_jurisdictions.index.continue_setup', 'Continue setup →') }}
                    </button>
                    <span v-if="setupMsg" class="text-gray-400">{{ setupMsg }}</span>
                    <button type="button" :disabled="bulkBusy || selectedCount === 0" @click="activateSelected"
                            class="px-2.5 py-1 rounded-l border border-emerald-600 text-emerald-200 hover:bg-emerald-900/40 disabled:opacity-40 transition-colors">
                        {{ bulkBusy ? t('c_jurisdictions.index.activating', 'Activating…') : t('c_jurisdictions.index.activate_selected', { count: selectedCount }) }}
                    </button>
                    <button type="button" :disabled="bulkBusy || selectedCount === 0" @click="activateSelectedChildren"
                            :title="t('c_jurisdictions.index.activate_children_title', 'Each selected jurisdiction AND its whole subtree (queued)')"
                            class="px-2 py-1 rounded-r border border-l-0 border-emerald-600 text-emerald-300 hover:bg-emerald-900/40 disabled:opacity-40 transition-colors">
                        {{ t('c_jurisdictions.index.plus_children', '+ children') }}
                    </button>
                    <button v-if="halfCount" type="button" :disabled="healBusy" @click="finishActivations"
                            :title="t('c_jurisdictions.index.finish_all_title', 'These places have seats but no election board, so district plans cannot be accepted')"
                            class="px-2.5 py-1 rounded border border-amber-500 bg-amber-900/30 text-amber-100 hover:bg-amber-900/60 disabled:opacity-50 transition-colors">
                        <span v-if="healBusy" class="inline-block animate-spin">◠</span>
                        {{ healBusy ? t('c_jurisdictions.index.booting_left', { n: halfCount.toLocaleString() }) : t('c_jurisdictions.index.finish_activation_n', { n: halfCount.toLocaleString() }) }}
                    </button>
                    <button type="button" :disabled="allBusy" @click="activateAll"
                            class="px-2.5 py-1 rounded border border-violet-600 text-violet-200 hover:bg-violet-900/40 disabled:opacity-50 transition-colors">
                        {{ allBusy ? t('c_jurisdictions.index.starting', 'Starting…') : t('c_jurisdictions.index.activate_all', 'Activate All — planet-wide build') }}
                    </button>
                    <span v-if="healMsg" class="text-gray-400">{{ healMsg }}</span>
                    <span v-if="bulkMsg" class="text-gray-400">{{ bulkMsg }}</span>
                    <span v-if="allMsg" class="text-gray-400">{{ allMsg }}</span>
                </span>
            </div>

            <!-- Toolbar -->
            <div class="flex items-center gap-3 px-6 py-3 bg-gray-900 border-b border-gray-800 shrink-0">
                <h1 class="text-sm font-semibold text-gray-200 mr-2">{{ t('c_jurisdictions.index.toolbar_title', 'Jurisdictions') }}</h1>

                <!-- Search -->
                <input
                    v-model="search"
                    @input="onSearch"
                    type="text"
                    :aria-label="t('c_jurisdictions.index.search_aria', 'Search jurisdictions by name')"
                    :placeholder="t('c_jurisdictions.index.search_ph', 'Search by name…')"
                    class="w-64 bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-blue-500"
                />

                <!-- Activation filter (operator tour, 2026-08-08) -->
                <select
                    v-model="activeFilter"
                    @change="onFilter"
                    :aria-label="t('c_jurisdictions.index.filter_activation_aria', 'Filter by activation')"
                    class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-white focus:outline-none focus:border-blue-500"
                >
                    <option value="">{{ t('c_jurisdictions.index.f_active_inactive', 'Active & inactive') }}</option>
                    <option value="1">{{ t('c_jurisdictions.index.f_activated', 'Activated only') }}</option>
                    <option value="0">{{ t('c_jurisdictions.index.f_not_activated', 'Not activated') }}</option>
                </select>

                <!-- ADM level filter -->
                <select
                    v-model="admLevel"
                    @change="onFilter"
                    :aria-label="t('c_jurisdictions.index.filter_adm_aria', 'Filter by administrative level')"
                    class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-white focus:outline-none focus:border-blue-500"
                >
                    <option value="">{{ t('c_jurisdictions.index.l_all', 'All levels') }}</option>
                    <option value="0">{{ t('c_jurisdictions.index.l_adm0', 'ADM 0 — World') }}</option>
                    <option value="1">{{ t('c_jurisdictions.index.l_adm1', 'ADM 1 — Country') }}</option>
                    <option value="2">{{ t('c_jurisdictions.index.l_adm2', 'ADM 2 — State / Province') }}</option>
                    <option value="3">{{ t('c_jurisdictions.index.l_adm3', 'ADM 3 — County / District') }}</option>
                    <option value="4">{{ t('c_jurisdictions.index.l_adm4', 'ADM 4') }}</option>
                    <option value="5">{{ t('c_jurisdictions.index.l_adm5', 'ADM 5') }}</option>
                    <option value="6">{{ t('c_jurisdictions.index.l_adm6', 'ADM 6') }}</option>
                </select>

                <span class="ml-auto text-xs text-gray-500">
                    {{ t('c_jurisdictions.index.total_count', { count: jurisdictions.total.toLocaleString() }) }}
                </span>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto">
                <table class="w-full text-sm border-collapse">
                    <thead class="sticky top-0 bg-gray-900 z-10">
                        <tr class="text-left text-xs text-gray-400 uppercase tracking-wide">
                            <th v-if="isOperator" class="px-2 py-2 font-medium border-b border-gray-800 w-8" @click.stop>
                                <input type="checkbox" v-model="allSelected" :title="t('c_jurisdictions.index.select_all_title', 'Select all on this page')" class="accent-emerald-600" />
                            </th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 w-48">{{ t('c_jurisdictions.index.th_level', 'Level') }}</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800">{{ t('c_jurisdictions.index.th_name', 'Name') }}</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 w-44">{{ t('c_jurisdictions.index.th_legislature', 'Legislature') }}</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 font-mono text-xs">{{ t('c_jurisdictions.index.th_slug', 'Slug') }}</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 text-right">{{ t('c_jurisdictions.index.th_population', 'Population') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="j in jurisdictions.data"
                            :key="j.id"
                            @click="visit(j.slug)"
                            class="border-b border-gray-800/60 hover:bg-gray-800/50 cursor-pointer transition-colors"
                        >
                            <td v-if="isOperator" class="px-2 py-2" @click.stop>
                                <input type="checkbox" v-model="selected[j.id]" :aria-label="t('c_jurisdictions.index.select_row_aria', 'Select this jurisdiction')" class="accent-emerald-600" />
                            </td>
                            <td class="px-4 py-2">
                                <span class="inline-block text-xs px-2 py-0.5 rounded-full bg-blue-900/60 text-blue-300">
                                    {{ admLabel(j.adm_level) }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-medium text-white">
                                {{ j.name }}
                                <!-- The lineage chain (operator tour, 2026-08-08):
                                     same-named places are only tellable apart by
                                     where they sit in the tree. -->
                                <div v-if="j.chain" class="text-[11px] font-normal text-gray-500">{{ j.chain }}</div>
                            </td>
                            <!-- Activate / mapper actions (manual-first arc,
                                 operator 2026-08-06). @click.stop — the row
                                 itself navigates to the viewer. -->
                            <td class="px-4 py-2" @click.stop>
                                <template v-if="j.legislature_id">
                                    <!-- HALF-ACTIVATED (operator-caught,
                                         2026-08-08): seats exist but no
                                         election board, so the mapper's
                                         Accept-plan filing has no R-08 to
                                         stand on. One click completes the
                                         boot; idempotent. -->
                                    <button v-if="isOperator && !j.has_board"
                                            type="button" :disabled="!!busy[j.id]"
                                            @click="activateRow(j)"
                                            :title="t('c_jurisdictions.index.finish_row_title', 'Seats exist but this place never finished activation — no election board, so district plans cannot be accepted')"
                                            class="mr-1 inline-block text-xs px-2.5 py-1 rounded border border-amber-500
                                                   bg-amber-900/30 text-amber-100 hover:bg-amber-900/60 disabled:opacity-50 transition-colors">
                                        {{ busy[j.id] ? t('c_jurisdictions.index.booting', 'Booting…') : t('c_jurisdictions.index.finish_activation', 'Finish activation') }}
                                    </button>
                                    <a :href="`/legislatures/${j.slug}/districts`"
                                       class="inline-block text-xs px-2.5 py-1 rounded border border-emerald-600
                                              text-emerald-200 hover:bg-emerald-900/40 transition-colors">
                                        {{ t('c_jurisdictions.index.districts_link', 'Districts →') }}
                                    </a>
                                    <!-- Simulate = the fake people/orgs/bills build,
                                         a SEPARATE act from activation (operator
                                         tour, 2026-08-08). Sandbox worlds only. -->
                                    <button v-if="isOperator && scale?.is_sandbox"
                                            type="button" :disabled="!!busy[j.id]"
                                            @click="simulateRow(j)"
                                            :title="t('c_jurisdictions.index.simulate_title', 'Populate this jurisdiction\'s subtree with simulated residents, elections, orgs, and bills')"
                                            class="ml-1 inline-block text-xs px-2.5 py-1 rounded border border-amber-600
                                                   text-amber-200 hover:bg-amber-900/40 disabled:opacity-50 transition-colors">
                                        {{ t('c_jurisdictions.index.simulate', 'Simulate') }}
                                    </button>
                                </template>
                                <template v-else-if="isOperator">
                                    <button type="button"
                                            :disabled="!!busy[j.id]"
                                            @click="activateRow(j)"
                                            class="inline-block text-xs px-2.5 py-1 rounded border border-violet-600
                                                   text-violet-200 hover:bg-violet-900/40 disabled:opacity-50 transition-colors">
                                        {{ busy[j.id] ? t('c_jurisdictions.index.sizing', 'Sizing…') : t('c_jurisdictions.index.activate', 'Activate') }}
                                    </button>
                                </template>
                                <!-- "+ children" is driven by the SUBTREE's
                                     state, not this row's (operator,
                                     2026-08-08): a parent can be activated
                                     while its children are not, and the
                                     button must stay for exactly that case. -->
                                <!-- Running: the bar takes the button's place
                                     and tracks the WHOLE subtree (the label
                                     never claimed a count — direct children
                                     are not what gets queued). -->
                                <span v-if="subtreeProgress[j.id]"
                                      class="ml-1 inline-flex items-center gap-1.5 align-middle"
                                      :title="t('c_jurisdictions.index.subtree_title', { processed: subtreeProgress[j.id].processed, total: subtreeProgress[j.id].total })">
                                    <span class="inline-block w-24 h-1.5 rounded bg-gray-700 overflow-hidden align-middle">
                                        <span class="block h-full rounded transition-all duration-500"
                                              :class="subtreeProgress[j.id].finished ? 'bg-emerald-500' : 'bg-violet-500'"
                                              :style="{ width: subtreePct(j) + '%' }"></span>
                                    </span>
                                    <span class="text-[10px] tabular-nums"
                                          :class="subtreeProgress[j.id].finished ? 'text-emerald-300' : 'text-violet-300'">
                                        {{ subtreeProgress[j.id].finished
                                            ? t('c_jurisdictions.index.subtree_done', { n: subtreeProgress[j.id].total.toLocaleString() })
                                            : `${subtreeProgress[j.id].processed.toLocaleString()}/${subtreeProgress[j.id].total.toLocaleString()}` }}
                                    </span>
                                </span>
                                <button v-else-if="isOperator && j.inactive_children"
                                        type="button"
                                        :disabled="!!busy[j.id]"
                                        @click="activateChildren(j)"
                                        :title="t('c_jurisdictions.index.row_children_title', 'Activate this jurisdiction and its whole subtree (queued)')"
                                        class="ml-1 inline-block text-xs px-2 py-1 rounded border border-violet-600
                                               text-violet-300 hover:bg-violet-900/40 disabled:opacity-50 transition-colors">
                                    {{ t('c_jurisdictions.index.plus_children', '+ children') }}
                                </button>
                                <span v-else class="text-xs text-gray-600">—</span>
                                <span v-if="rowErr[j.id]" class="ml-2 text-xs text-red-400">{{ rowErr[j.id] }}</span>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-400">{{ j.slug }}</td>
                            <td class="px-4 py-2 text-right text-gray-300 tabular-nums">
                                {{ j.population ? Number(j.population).toLocaleString() : '—' }}
                                <span v-if="j.population_year" class="text-gray-600 text-xs ml-1">'{{ String(j.population_year).slice(-2) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="flex items-center justify-between px-6 py-3 bg-gray-900 border-t border-gray-800 shrink-0 text-xs text-gray-400">
                <span class="flex items-center gap-2">
                    <label class="flex items-center gap-1.5">
                        <span class="text-gray-500">{{ t('c_jurisdictions.index.rows', 'Rows') }}</span>
                        <select v-model="perPage" @change="onFilter"
                                class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-white focus:outline-none focus:border-blue-500">
                            <option v-for="n in (per_page_options || [25,50,100,200])" :key="n" :value="String(n)">{{ n }}</option>
                        </select>
                    </label>
                    <span>
                        {{ t('c_jurisdictions.index.showing', { from: jurisdictions.from?.toLocaleString() ?? 0, to: jurisdictions.to?.toLocaleString() ?? 0, total: jurisdictions.total.toLocaleString() }) }}
                    </span>
                </span>
                <div class="flex gap-1">
                    <component
                        v-for="link in jurisdictions.links"
                        :key="link.label"
                        :is="link.url ? 'button' : 'span'"
                        @click="link.url && goToPage(link.url)"
                        v-html="link.label"
                        class="px-2 py-1 rounded text-xs transition-colors"
                        :class="{
                            'bg-blue-700 text-white': link.active,
                            'hover:bg-gray-700 cursor-pointer text-gray-300': link.url && !link.active,
                            'text-gray-600 cursor-default': !link.url,
                        }"
                    />
                </div>
            </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import { csrfFetch } from '@/lib/csrf'

// Table-led tool surface: full chrome + flush main, reproducing the legacy
// full-height column (toolbar / scrolling table / pinned pagination).
// Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN).
defineOptions({
    layout: (h, page) => h(AppShellV2, { variant: 'flush' }, () => page),
})

const { t } = useI18n()

const admLabels = {
    0: t('c_jurisdictions.index.adm_world', 'World'),
    1: t('c_jurisdictions.index.adm_country', 'Country'),
    2: t('c_jurisdictions.index.adm_state', 'State / Province'),
    3: t('c_jurisdictions.index.adm_county', 'County / District'),
    4: t('c_jurisdictions.index.adm_4', 'ADM 4'),
    5: t('c_jurisdictions.index.adm_5', 'ADM 5'),
    6: t('c_jurisdictions.index.adm_6', 'ADM 6'),
}

function admLabel(level) {
    return admLabels[level] ?? t('c_jurisdictions.index.adm_n', { level })
}

const props = defineProps({
    jurisdictions: Object,
    filters: Object,
    scale: Object,             // { mode, map_accepted_at, is_sandbox, half_activated }
    per_page_options: Array,   // allowlisted rows-per-page choices
})

const search       = ref(props.filters?.search    ?? '')
const admLevel     = ref(props.filters?.adm_level ?? '')
const activeFilter = ref(props.filters?.active    ?? '')
const perPage      = ref(String(props.filters?.per_page ?? 50))

// ACTIVATE-per-row (manual-first arc, operator 2026-08-06): sizes ONE
// jurisdiction's legislature (apportionment:seed via the endpoint) and swaps
// the button for the Districts link in place — no page reload, the 940k-row
// table keeps its position.
const isOperator = computed(() => !! usePage().props.auth?.user?.is_operator)
const busy   = ref({})
const rowErr = ref({})

async function activateRow(j) {
    if (busy.value[j.id]) return
    busy.value   = { ...busy.value, [j.id]: true }
    rowErr.value = { ...rowErr.value, [j.id]: '' }
    try {
        const res = await csrfFetch(`/api/jurisdictions/${j.id}/activate-legislature`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    '{}',
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            rowErr.value = { ...rowErr.value, [j.id]: data.error || `HTTP ${res.status}` }
            return
        }
        j.legislature_id = data.legislature_id   // in-place swap → Districts link
        j.has_board = !!data.has_board           // clears "Finish activation"
        if (!data.has_board) {
            rowErr.value = { ...rowErr.value, [j.id]: t('c_jurisdictions.index.board_not_constitute', 'activated, but the election board did not constitute — check the logs') }
        }
    } catch (e) {
        rowErr.value = { ...rowErr.value, [j.id]: String(e?.message || e) }
    } finally {
        busy.value = { ...busy.value, [j.id]: false }
    }
}

// ── The harmonized activation surface (operator, 2026-08-08) ────────────────
// Selection + Activate Selected (sequential, per-row feedback), per-row
// "+ children recursively" (queued subtree job; big trees are refused toward
// Activate All), and Activate All = the planet-wide build (re-hook).
const MODE_LABEL = {
    eager:      t('c_jurisdictions.index.mode_eager', 'Activate & Scale Institutions Now'),
    population: t('c_jurisdictions.index.mode_population', 'Activate & Scale Institutions As Players Join'),
    manual:     t('c_jurisdictions.index.mode_manual', 'Activate & Scale Institutions Manually'),
}
const selected = ref({})
const selectedCount = computed(() => Object.values(selected.value).filter(Boolean).length)

// Select-all for the visible page (operator tour, 2026-08-08).
const allSelected = computed({
    get: () => props.jurisdictions.data.length > 0
        && props.jurisdictions.data.every(j => !!selected.value[j.id]),
    set: (v) => {
        const next = { ...selected.value }
        for (const j of props.jurisdictions.data) next[j.id] = v
        selected.value = next
    },
})

// The list + mapper ARE step 3 in practice (operator, 2026-08-08): give the
// wizard rails here — back to Step 2, or advance the wizard and continue to
// the review/confirm step when this surface's work is done.
const advancingSetup = ref(false)
const setupMsg = ref('')

async function continueSetup() {
    if (advancingSetup.value) return
    advancingSetup.value = true
    setupMsg.value = ''
    try {
        const res = await csrfFetch('/api/setup/wizard/step1/activate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: '{}',
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            setupMsg.value = data.error || t('c_jurisdictions.index.advance_failed', { status: res.status })
            return
        }
        router.visit('/setup/step/3')
    } catch (e) {
        setupMsg.value = String(e?.message || e)
    } finally {
        advancingSetup.value = false
    }
}
const bulkBusy = ref(false)
const bulkMsg  = ref('')

async function activateSelected() {
    if (bulkBusy.value) return
    bulkBusy.value = true
    bulkMsg.value = ''
    let done = 0
    try {
        for (const j of props.jurisdictions.data) {
            // Skip only rows that are FULLY activated (seats AND board) — a
            // half-activated row must still be bootable in bulk, or the heal
            // path is unreachable exactly where it is needed (the b3161b9
            // lesson, 2026-08-08).
            if (!selected.value[j.id] || (j.legislature_id && j.has_board)) continue
            await activateRow(j)
            if (j.legislature_id && j.has_board) done++
        }
        bulkMsg.value = t('c_jurisdictions.index.activated_n', { done, total: selectedCount.value })
        selected.value = {}
    } finally {
        bulkBusy.value = false
    }
}

// The companion to Activate Selected (operator tour, 2026-08-08): each
// selected row's whole subtree, queued — same per-row recursive endpoint,
// same big-tree refusal toward Activate All.
async function activateSelectedChildren() {
    if (bulkBusy.value) return
    bulkBusy.value = true
    bulkMsg.value = ''
    let queued = 0
    try {
        for (const j of props.jurisdictions.data) {
            if (!selected.value[j.id]) continue
            await activateChildren(j)
            if ((rowErr.value[j.id] || '').startsWith('queued')) queued++
        }
        bulkMsg.value = t('c_jurisdictions.index.queued_n', { queued, total: selectedCount.value })
        selected.value = {}
    } finally {
        bulkBusy.value = false
    }
}

// The narrow co-test (operator, 2026-08-08): simulate THIS jurisdiction's
// subtree — sandbox worlds only; the backend also requires activation first.
async function simulateRow(j) {
    if (busy.value[j.id]) return
    busy.value   = { ...busy.value, [j.id]: true }
    rowErr.value = { ...rowErr.value, [j.id]: '' }
    try {
        const res = await csrfFetch(`/api/jurisdictions/${j.id}/simulate`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    '{}',
        })
        const data = await res.json().catch(() => ({}))
        rowErr.value = { ...rowErr.value,
            [j.id]: (!res.ok || !data.ok) ? (data.error || `HTTP ${res.status}`) : t('c_jurisdictions.index.simulation_queued', 'simulation queued') }
    } catch (e) {
        rowErr.value = { ...rowErr.value, [j.id]: String(e?.message || e) }
    } finally {
        busy.value = { ...busy.value, [j.id]: false }
    }
}

// Per-row subtree progress: { [jurisdictionId]: {total, processed, finished} }
// fed by ActivateSubtreeJob's published counters, so the bar tracks real
// work instead of a "queued" message that never moves.
const subtreeProgress = ref({})
const subtreePolls = {}

function stopSubtreePoll(id) {
    if (subtreePolls[id]) { clearInterval(subtreePolls[id]); delete subtreePolls[id] }
}

function startSubtreePoll(j) {
    stopSubtreePoll(j.id)
    subtreePolls[j.id] = setInterval(async () => {
        try {
            const res = await fetch(`/api/jurisdictions/${j.id}/subtree-progress`, {
                headers: { Accept: 'application/json' },
            })
            if (!res.ok) return
            const { progress } = await res.json()
            if (!progress) {           // finished and expired, or never started
                stopSubtreePoll(j.id)
                subtreeProgress.value = { ...subtreeProgress.value, [j.id]: undefined }
                j.inactive_children = 0
                return
            }
            subtreeProgress.value = { ...subtreeProgress.value, [j.id]: progress }
            if (progress.finished) {
                stopSubtreePoll(j.id)
                j.inactive_children = 0
                setTimeout(() => {
                    subtreeProgress.value = { ...subtreeProgress.value, [j.id]: undefined }
                }, 4000)
            }
        } catch { /* transient — next tick retries */ }
    }, 2000)
}

async function activateChildren(j) {
    if (busy.value[j.id]) return
    busy.value   = { ...busy.value, [j.id]: true }
    rowErr.value = { ...rowErr.value, [j.id]: '' }
    try {
        const res = await csrfFetch(`/api/jurisdictions/${j.id}/activate-legislature`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ recursive: true }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            rowErr.value = { ...rowErr.value, [j.id]: data.error || `HTTP ${res.status}` }
            return
        }
        // Seed the bar immediately so the click has a visible effect before
        // the job's first publish lands.
        subtreeProgress.value = { ...subtreeProgress.value, [j.id]: {
            total: Number(data.subtree_count || 0), processed: 0, finished: false,
        } }
        startSubtreePoll(j)
    } catch (e) {
        rowErr.value = { ...rowErr.value, [j.id]: String(e?.message || e) }
    } finally {
        busy.value = { ...busy.value, [j.id]: false }
    }
}

// Bulk heal: boot every half-activated place (legislature, no election
// board) in one queued pass — hundreds of rows are normal after a subtree
// queue, and nine pages of clicking is not a heal path.
const healBusy = ref(false)
const healMsg  = ref('')
// LIVE count (operator, 2026-08-08: "a progress indicator that runs would be
// nice") — the badge polls while the queued boot drains, instead of sitting
// frozen until a manual refresh.
const halfCount = ref(Number(props.scale?.half_activated ?? 0))
let healPoll = null

async function pollHalfCount() {
    try {
        const res = await fetch('/api/jurisdictions/activation-status', {
            headers: { Accept: 'application/json' },
        })
        if (!res.ok) return
        const data = await res.json()
        const prev = halfCount.value
        halfCount.value = Number(data.half_activated ?? 0)
        if (halfCount.value === 0) {
            stopHealPoll()
            healMsg.value = t('c_jurisdictions.index.all_have_board', 'All activated places have their election board.')
            healBusy.value = false
        } else if (halfCount.value !== prev) {
            healMsg.value = t('c_jurisdictions.index.booting_remaining', { n: halfCount.value.toLocaleString() })
        }
    } catch { /* transient — the next tick retries */ }
}

function stopHealPoll() {
    if (healPoll) { clearInterval(healPoll); healPoll = null }
}

async function finishActivations() {
    if (healBusy.value) return
    healBusy.value = true
    healMsg.value = ''
    try {
        const res = await csrfFetch('/api/jurisdictions/finish-activations', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: '{}',
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            healMsg.value = data.error || `HTTP ${res.status}`
            healBusy.value = false
            return
        }
        if (!data.queued) {
            healMsg.value = t('c_jurisdictions.index.nothing_to_finish', 'Nothing to finish — every activated place has its board.')
            healBusy.value = false
            return
        }
        // Stay "busy" while the queued job drains; the poll ends it.
        healMsg.value = t('c_jurisdictions.index.booting_remaining', { n: Number(data.count).toLocaleString() })
        stopHealPoll()
        healPoll = setInterval(pollHalfCount, 2500)
    } catch (e) {
        healMsg.value = String(e?.message || e)
        healBusy.value = false
    }
}

// Adopt any run already in flight when the page loads (his refresh habit
// must not orphan a running bar).
for (const j of props.jurisdictions.data) {
    if (j.subtree_progress) {
        subtreeProgress.value[j.id] = j.subtree_progress
        if (!j.subtree_progress.finished) startSubtreePoll(j)
    }
}

function subtreePct(j) {
    const p = subtreeProgress.value[j.id]
    if (!p || !p.total) return 0
    return Math.min(100, Math.round((p.processed / p.total) * 100))
}

onBeforeUnmount(() => {
    stopHealPoll()
    Object.keys(subtreePolls).forEach(stopSubtreePoll)
})

const allBusy = ref(false)
const allMsg  = ref('')

async function activateAll() {
    if (allBusy.value) return
    if (!confirm(t('c_jurisdictions.index.confirm_activate_all', 'Activate ALL: start the planet-wide build (every legislature sized, every founding map drawn)?'))) return
    allBusy.value = true
    allMsg.value = ''
    try {
        const res = await csrfFetch('/api/jurisdictions/accept-maps', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ start_autoscale: true }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
            allMsg.value = data.error || t('c_jurisdictions.index.start_failed', { status: res.status })
            return
        }
        allMsg.value = data.autoscale_run_id
            ? t('c_jurisdictions.index.build_running', { run: String(data.autoscale_run_id).slice(0, 8) })
            : t('c_jurisdictions.index.build_started', 'Planet-wide build started.')
    } catch (e) {
        allMsg.value = String(e?.message || e)
    } finally {
        allBusy.value = false
    }
}

let searchTimer = null

function onSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => applyFilters(), 350)
}

function onFilter() {
    applyFilters()
}

function applyFilters() {
    router.get('/jurisdictions', {
        view: 'operations',
        search:    search.value || undefined,
        adm_level: admLevel.value !== '' ? admLevel.value : undefined,
        active:    activeFilter.value !== '' ? activeFilter.value : undefined,
        // Changing rows-per-page returns to page 1 — page 9 of 50/page does
        // not exist at 200/page.
        per_page:  perPage.value !== '50' ? perPage.value : undefined,
    }, {
        preserveState: true,
        replace: true,
    })
}

function goToPage(url) {
    router.visit(url, { preserveState: true })
}

function visit(slug) {
    // Slug-based URLs for the public viewer; UUID-bound API endpoints stay
    // unchanged. Slugs are unique per parent and human-readable.
    router.visit(`/jurisdictions/${slug}`)
}
</script>
