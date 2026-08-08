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
                        ? 'The full-scale build runs on its own; these controls are for reruns and spot work.'
                        : scale.mode === 'population'
                            ? 'Places boot automatically as verified residents cross their threshold — or activate them here ahead of demand.'
                            : 'Nothing is automatic: Activate rows here (or + children), draw maps, build institutions through their forms.' }}
                </span>
                <span class="ml-auto flex items-center gap-2">
                    <button type="button" :disabled="bulkBusy || selectedCount === 0" @click="activateSelected"
                            class="px-2.5 py-1 rounded border border-emerald-600 text-emerald-200 hover:bg-emerald-900/40 disabled:opacity-40 transition-colors">
                        {{ bulkBusy ? 'Activating…' : `Activate selected (${selectedCount})` }}
                    </button>
                    <button type="button" :disabled="allBusy" @click="activateAll"
                            class="px-2.5 py-1 rounded border border-violet-600 text-violet-200 hover:bg-violet-900/40 disabled:opacity-50 transition-colors">
                        {{ allBusy ? 'Starting…' : 'Activate All — planet-wide build' }}
                    </button>
                    <span v-if="bulkMsg" class="text-gray-400">{{ bulkMsg }}</span>
                    <span v-if="allMsg" class="text-gray-400">{{ allMsg }}</span>
                </span>
            </div>

            <!-- Toolbar -->
            <div class="flex items-center gap-3 px-6 py-3 bg-gray-900 border-b border-gray-800 shrink-0">
                <h1 class="text-sm font-semibold text-gray-200 mr-2">Jurisdictions</h1>

                <!-- Search -->
                <input
                    v-model="search"
                    @input="onSearch"
                    type="text"
                    placeholder="Search by name…"
                    class="w-64 bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-blue-500"
                />

                <!-- ADM level filter -->
                <select
                    v-model="admLevel"
                    @change="onFilter"
                    class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-white focus:outline-none focus:border-blue-500"
                >
                    <option value="">All levels</option>
                    <option value="0">ADM 0 — World</option>
                    <option value="1">ADM 1 — Country</option>
                    <option value="2">ADM 2 — State / Province</option>
                    <option value="3">ADM 3 — County / District</option>
                    <option value="4">ADM 4</option>
                    <option value="5">ADM 5</option>
                    <option value="6">ADM 6</option>
                </select>

                <span class="ml-auto text-xs text-gray-500">
                    {{ jurisdictions.total.toLocaleString() }} jurisdictions
                </span>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto">
                <table class="w-full text-sm border-collapse">
                    <thead class="sticky top-0 bg-gray-900 z-10">
                        <tr class="text-left text-xs text-gray-400 uppercase tracking-wide">
                            <th v-if="isOperator" class="px-2 py-2 font-medium border-b border-gray-800 w-8"></th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 w-48">Level</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800">Name</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 w-44">Legislature</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 font-mono text-xs">Slug</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 text-right">Population</th>
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
                                <input type="checkbox" v-model="selected[j.id]" class="accent-emerald-600" />
                            </td>
                            <td class="px-4 py-2">
                                <span class="inline-block text-xs px-2 py-0.5 rounded-full bg-blue-900/60 text-blue-300">
                                    {{ admLabel(j.adm_level) }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-medium text-white">{{ j.name }}</td>
                            <!-- Activate / mapper actions (manual-first arc,
                                 operator 2026-08-06). @click.stop — the row
                                 itself navigates to the viewer. -->
                            <td class="px-4 py-2" @click.stop>
                                <a v-if="j.legislature_id"
                                   :href="`/legislatures/${j.slug}/districts`"
                                   class="inline-block text-xs px-2.5 py-1 rounded border border-emerald-600
                                          text-emerald-200 hover:bg-emerald-900/40 transition-colors">
                                    Districts →
                                </a>
                                <template v-else-if="isOperator">
                                    <button type="button"
                                            :disabled="!!busy[j.id]"
                                            @click="activateRow(j)"
                                            class="inline-block text-xs px-2.5 py-1 rounded-l border border-violet-600
                                                   text-violet-200 hover:bg-violet-900/40 disabled:opacity-50 transition-colors">
                                        {{ busy[j.id] ? 'Sizing…' : 'Activate' }}
                                    </button>
                                    <button type="button"
                                            :disabled="!!busy[j.id]"
                                            @click="activateChildren(j)"
                                            title="Activate this jurisdiction and its whole subtree (queued)"
                                            class="inline-block text-xs px-2 py-1 rounded-r border border-l-0 border-violet-600
                                                   text-violet-300 hover:bg-violet-900/40 disabled:opacity-50 transition-colors">
                                        + children
                                    </button>
                                </template>
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
                <span>
                    Showing {{ jurisdictions.from?.toLocaleString() ?? 0 }}–{{ jurisdictions.to?.toLocaleString() ?? 0 }}
                    of {{ jurisdictions.total.toLocaleString() }}
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
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import { csrfFetch } from '@/lib/csrf'

// Table-led tool surface: full chrome + flush main, reproducing the legacy
// full-height column (toolbar / scrolling table / pinned pagination).
// Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN).
defineOptions({
    layout: (h, page) => h(AppShellV2, { variant: 'flush' }, () => page),
})

const admLabels = {
    0: 'World',
    1: 'Country',
    2: 'State / Province',
    3: 'County / District',
    4: 'ADM 4',
    5: 'ADM 5',
    6: 'ADM 6',
}

function admLabel(level) {
    return admLabels[level] ?? `ADM ${level}`
}

const props = defineProps({
    jurisdictions: Object,
    filters: Object,
    scale: Object,   // { mode, map_accepted_at } — the activation-mode context
})

const search   = ref(props.filters?.search    ?? '')
const admLevel = ref(props.filters?.adm_level ?? '')

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
    eager:      'Activate & Scale Institutions Now',
    population: 'Activate & Scale Institutions As Players Join',
    manual:     'Activate & Scale Institutions Manually',
}
const selected = ref({})
const selectedCount = computed(() => Object.values(selected.value).filter(Boolean).length)
const bulkBusy = ref(false)
const bulkMsg  = ref('')

async function activateSelected() {
    if (bulkBusy.value) return
    bulkBusy.value = true
    bulkMsg.value = ''
    let done = 0
    try {
        for (const j of props.jurisdictions.data) {
            if (!selected.value[j.id] || j.legislature_id) continue
            await activateRow(j)
            if (j.legislature_id) done++
        }
        bulkMsg.value = `Activated ${done} of ${selectedCount.value} selected.`
        selected.value = {}
    } finally {
        bulkBusy.value = false
    }
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
        rowErr.value = { ...rowErr.value, [j.id]: `queued: ${Number(data.subtree_count).toLocaleString()} jurisdictions` }
    } catch (e) {
        rowErr.value = { ...rowErr.value, [j.id]: String(e?.message || e) }
    } finally {
        busy.value = { ...busy.value, [j.id]: false }
    }
}

const allBusy = ref(false)
const allMsg  = ref('')

async function activateAll() {
    if (allBusy.value) return
    if (!confirm('Activate ALL: start the planet-wide build (every legislature sized, every founding map drawn)?')) return
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
            allMsg.value = data.error || `start failed (HTTP ${res.status})`
            return
        }
        allMsg.value = data.autoscale_run_id
            ? `Planet-wide build running (run ${String(data.autoscale_run_id).slice(0, 8)}…)`
            : 'Planet-wide build started.'
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
        search:    search.value || undefined,
        adm_level: admLevel.value !== '' ? admLevel.value : undefined,
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
