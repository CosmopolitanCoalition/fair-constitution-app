<template>
    <AppLayout>
        <div class="flex flex-col h-full bg-gray-950 text-white">

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
                            <th class="px-4 py-2 font-medium border-b border-gray-800 w-48">Level</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800">Name</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 font-mono text-xs">Slug</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 text-right">Population</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 text-right" title="Population-proportional seats in parent legislature">A Seats</th>
                            <th class="px-4 py-2 font-medium border-b border-gray-800 text-right" title="Equal-house seats in parent legislature">B Seats</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="j in jurisdictions.data"
                            :key="j.id"
                            @click="visit(j.id)"
                            class="border-b border-gray-800/60 hover:bg-gray-800/50 cursor-pointer transition-colors"
                        >
                            <td class="px-4 py-2">
                                <span class="inline-block text-xs px-2 py-0.5 rounded-full bg-blue-900/60 text-blue-300">
                                    {{ admLabel(j.adm_level) }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-medium text-white">{{ j.name }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-400">{{ j.slug }}</td>
                            <td class="px-4 py-2 text-right text-gray-300 tabular-nums">
                                {{ j.population ? Number(j.population).toLocaleString() : '—' }}
                                <span v-if="j.population_year" class="text-gray-600 text-xs ml-1">'{{ String(j.population_year).slice(-2) }}</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums" :class="j.type_a_apportioned ? 'text-green-400 font-semibold' : 'text-gray-600'">
                                {{ j.type_a_apportioned ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums" :class="j.type_b_apportioned ? 'text-blue-400' : 'text-gray-600'">
                                {{ j.type_b_apportioned ?? '—' }}
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
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

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
})

const search   = ref(props.filters?.search    ?? '')
const admLevel = ref(props.filters?.adm_level ?? '')

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

function visit(id) {
    router.visit(`/jurisdictions/${id}`)
}
</script>
