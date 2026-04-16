<template>
    <AppLayout>
        <div class="flex flex-1 min-h-0 overflow-hidden">

            <!-- ══ Left panel ══════════════════════════════════════════════ -->
            <!-- max-w-96 prevents content overflow from bleeding onto the map -->
            <aside class="w-96 max-w-96 shrink-0 bg-gray-900 border-r border-gray-800 flex flex-col overflow-hidden">

                <!-- Header -->
                <div class="px-4 py-3 border-b border-gray-800 shrink-0">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-xs text-gray-500 mb-0.5">Legislature Browser</div>
                            <h1 class="text-base font-bold text-white leading-tight truncate">{{ scope.name }}</h1>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="text-xs text-gray-500">Root seats</div>
                            <div class="text-base font-bold text-emerald-400">{{ legislature.type_a_seats.toLocaleString() }}</div>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        <span class="text-emerald-500 font-medium">{{ scope_seats.toLocaleString() }} seats</span>
                        to assign here
                        · Quota: {{ quota.toLocaleString() }} pop/seat
                    </div>
                    <!-- Optimal district config hint -->
                    <div v-if="optimalLabel" class="text-xs text-gray-400 mt-0.5 space-y-0.5">
                        <div>
                            <span class="text-gray-500">Optimal: </span>
                            <span class="text-cyan-400 font-medium">{{ optimalLabel }}</span>
                        </div>
                        <div v-if="currentConfigLabel">
                            <span class="text-gray-500">Current: </span>
                            <span class="text-amber-400">{{ currentConfigLabel }}</span>
                        </div>
                    </div>
                </div>

                <!-- Breadcrumb -->
                <div class="px-4 py-2 border-b border-gray-800 text-xs text-gray-400 flex flex-wrap gap-1 items-center shrink-0">
                    <template v-for="(anc, i) in ancestors" :key="anc.id">
                        <a v-if="i < ancestors.length - 1"
                           @click.prevent="drillTo(anc.id)" href="#"
                           class="hover:text-white transition-colors cursor-pointer">{{ anc.name }}</a>
                        <span v-else class="text-gray-200 font-medium">{{ anc.name }}</span>
                        <span v-if="i < ancestors.length - 1" class="text-gray-600">›</span>
                    </template>
                </div>

                <!-- Stats: 4 columns -->
                <div class="px-3 py-2 border-b border-gray-800 grid grid-cols-3 gap-1.5 text-center shrink-0">
                    <div class="bg-gray-800 rounded p-1.5">
                        <div class="text-xs text-gray-500">Districts</div>
                        <div class="text-sm font-semibold text-white">{{ districtsRef.length }}</div>
                    </div>
                    <div class="bg-gray-800 rounded p-1.5">
                        <div class="text-xs text-gray-500">Assigned</div>
                        <div class="text-sm font-semibold text-emerald-400">{{ assignedCount }}</div>
                    </div>
                    <div class="bg-gray-800 rounded p-1.5">
                        <div class="text-xs text-gray-500">Unassigned</div>
                        <div class="text-sm font-semibold text-amber-400">{{ unassignedAssignable.length }}</div>
                    </div>
                </div>

                <!-- Map selector bar -->
                <div class="px-3 py-2 border-b border-gray-800 shrink-0 relative">
                    <div class="flex items-center gap-1.5">
                        <span class="text-[10px] text-gray-500 uppercase tracking-wide shrink-0">Map:</span>
                        <!-- Dropdown trigger -->
                        <button @click="mapSelectorOpen = !mapSelectorOpen; newMapFormOpen = false"
                                class="flex-1 flex items-center justify-between gap-1.5 px-2 py-1 rounded text-xs bg-gray-800 border transition-colors min-w-0"
                                :class="mapSelectorOpen ? 'border-indigo-600 text-white' : 'border-gray-700 text-gray-200 hover:border-gray-500'">
                            <span class="truncate">{{ props.active_map?.name ?? '—' }}</span>
                            <span class="shrink-0 text-[10px] px-1 rounded"
                                  :class="props.active_map?.status === 'active'   ? 'text-emerald-400' :
                                          props.active_map?.status === 'archived' ? 'text-gray-600'    :
                                                                                    'text-amber-400'">
                                {{ props.active_map?.status ?? '' }}
                            </span>
                            <span class="text-gray-600 shrink-0 text-[10px]">▾</span>
                        </button>
                        <!-- Activate (draft only) -->
                        <button v-if="props.active_map?.status === 'draft'"
                                @click="activateCurrentMap"
                                title="Activate as official apportionment"
                                class="px-1.5 py-1 rounded text-xs border bg-amber-900 border-amber-700 text-amber-300 hover:bg-amber-800 transition-colors shrink-0">
                            ⚡
                        </button>
                        <!-- New map -->
                        <button @click="newMapFormOpen = !newMapFormOpen; mapSelectorOpen = false"
                                title="Create a new district map"
                                class="px-1.5 py-1 rounded text-xs border bg-gray-800 border-gray-700 text-gray-400 hover:text-emerald-400 hover:border-emerald-700 transition-colors shrink-0">
                            +
                        </button>
                    </div>

                    <!-- Map dropdown list -->
                    <div v-if="mapSelectorOpen"
                         class="absolute left-3 right-3 top-full mt-1 z-50 bg-gray-900 border border-gray-700 rounded shadow-xl overflow-hidden">
                        <template v-for="m in props.maps" :key="m.id">
                            <!-- Delete confirmation row -->
                            <div v-if="deletingMapId === m.id"
                                 class="flex items-center gap-1.5 px-3 py-2 bg-red-950 border-b border-red-900">
                                <span class="flex-1 text-xs text-red-300 truncate">Delete "{{ m.name }}"?</span>
                                <button @click.stop="confirmDeleteMap(m.id)"
                                        class="px-2 py-0.5 rounded text-[10px] bg-red-700 border border-red-600 text-white hover:bg-red-600 shrink-0">
                                    Delete
                                </button>
                                <button @click.stop="deletingMapId = null"
                                        class="px-2 py-0.5 rounded text-[10px] bg-gray-800 border border-gray-700 text-gray-400 hover:text-white shrink-0">
                                    Cancel
                                </button>
                            </div>
                            <!-- Rename row -->
                            <div v-else-if="renamingMapId === m.id"
                                 class="flex items-center gap-1.5 px-3 py-2 bg-gray-800 border-b border-gray-700">
                                <input v-model="renameValue"
                                       @keyup.enter="submitRename(m.id)"
                                       @keyup.escape="cancelRename"
                                       class="flex-1 px-1.5 py-0.5 rounded text-xs bg-gray-700 border border-gray-600 text-gray-200 focus:outline-none focus:border-indigo-500 min-w-0" />
                                <button @click.stop="submitRename(m.id)"
                                        :disabled="!renameValue.trim()"
                                        class="px-2 py-0.5 rounded text-[10px] border shrink-0 transition-colors"
                                        :class="renameValue.trim() ? 'bg-indigo-700 border-indigo-600 text-white hover:bg-indigo-600' : 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'">
                                    Save
                                </button>
                                <button @click.stop="cancelRename"
                                        class="px-2 py-0.5 rounded text-[10px] bg-gray-800 border border-gray-700 text-gray-400 hover:text-white shrink-0">
                                    ✕
                                </button>
                            </div>
                            <!-- Normal row -->
                            <div v-else
                                 class="flex items-center gap-2 px-3 py-2 text-xs transition-colors group"
                                 :class="m.id === props.active_map?.id
                                     ? 'bg-gray-700 text-white'
                                     : 'text-gray-300 hover:bg-gray-800 hover:text-white'">
                                <span @click="switchMap(m.id)" class="flex-1 truncate cursor-pointer">{{ m.name }}</span>
                                <span class="shrink-0 text-[10px]"
                                      :class="m.status === 'active' ? 'text-emerald-400' : m.status === 'archived' ? 'text-gray-600' : 'text-amber-400'">
                                    {{ m.status }}
                                </span>
                                <span class="shrink-0 text-gray-500 tabular-nums">{{ m.district_count ?? 0 }}d</span>
                                <span v-if="countFlags(m.flags) > 0" class="shrink-0 text-red-400 text-[10px]">
                                    ⛔{{ countFlags(m.flags) }}
                                </span>
                                <!-- Rename + copy + delete buttons (visible on hover) -->
                                <span class="shrink-0 flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button @click.stop="startRename(m)"
                                            title="Rename"
                                            class="px-1 py-0.5 rounded text-[10px] text-gray-400 hover:text-white hover:bg-gray-600 transition-colors">
                                        ✎
                                    </button>
                                    <button @click.stop="duplicateMap(m.id)"
                                            title="Duplicate"
                                            :disabled="copyingMapId === m.id"
                                            class="px-1 py-0.5 rounded text-[10px] transition-colors"
                                            :class="copyingMapId === m.id
                                                ? 'text-gray-600 cursor-wait'
                                                : 'text-gray-400 hover:text-sky-400 hover:bg-gray-600'">
                                        ⎘
                                    </button>
                                    <button @click.stop="deletingMapId = m.id"
                                            title="Delete"
                                            :disabled="m.status === 'active'"
                                            class="px-1 py-0.5 rounded text-[10px] transition-colors"
                                            :class="m.status === 'active'
                                                ? 'text-gray-700 cursor-not-allowed'
                                                : 'text-gray-400 hover:text-red-400 hover:bg-gray-600'">
                                        🗑
                                    </button>
                                </span>
                            </div>
                        </template>
                        <div v-if="props.maps.length === 0"
                             class="px-3 py-2 text-xs text-gray-500 italic">No maps yet</div>
                    </div>

                    <!-- New map inline form -->
                    <div v-if="newMapFormOpen" class="mt-2 flex items-center gap-1.5">
                        <input v-model="newMapName"
                               @keyup.enter="submitNewMap"
                               @keyup.escape="newMapFormOpen = false; newMapName = ''"
                               placeholder="Map name…"
                               class="flex-1 px-2 py-1 rounded text-xs bg-gray-800 border border-gray-600 text-gray-200 placeholder-gray-600 focus:outline-none focus:border-emerald-600 min-w-0" />
                        <button @click="submitNewMap"
                                :disabled="!newMapName.trim()"
                                class="px-2 py-1 rounded text-xs border transition-colors shrink-0"
                                :class="newMapName.trim()
                                    ? 'bg-emerald-700 border-emerald-600 text-white hover:bg-emerald-600'
                                    : 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'">
                            Create
                        </button>
                        <button @click="newMapFormOpen = false; newMapName = ''"
                                class="px-1.5 py-1 rounded text-xs border bg-gray-800 border-gray-700 text-gray-400 hover:text-white transition-colors shrink-0">
                            ✕
                        </button>
                    </div>
                </div>

                <!-- ── Districts ─────────────────────────────────────── -->
                <div class="flex-1 min-h-0 flex flex-col overflow-hidden">

                    <!-- Persistent mass-job progress banner (survives page navigation) -->
                    <div v-if="massJobRunning"
                         class="shrink-0 px-3 py-2 bg-indigo-950 border-b border-indigo-800 flex items-center gap-2">
                        <span class="inline-block w-3 h-3 rounded-full bg-indigo-400 animate-ping shrink-0"></span>
                        <span class="text-xs text-indigo-300 font-medium">
                            <template v-if="recolorProgress">
                                {{ recolorPhaseLabel(recolorProgress.phase, recolorProgress.total) }}
                            </template>
                            <template v-else>Mass operation in progress…</template>
                        </span>
                        <span v-if="recolorProgress && recolorElapsed"
                              class="text-[10px] text-indigo-400 ml-auto shrink-0">{{ recolorElapsed }} elapsed</span>
                        <span v-else class="text-[10px] text-indigo-500 ml-auto">Controls disabled</span>
                    </div>

                    <!-- Constitutional validation flags panel -->
                    <div v-if="hasAnyFlag"
                         class="mx-2 mt-2 mb-1 rounded border bg-gray-900 shrink-0"
                         :class="hardFlagCount > 0 ? 'border-red-900' : 'border-amber-900'">
                        <div class="flex items-center justify-between px-3 py-1.5 border-b border-gray-800 cursor-pointer"
                             @click="flagsPanelCollapsed = !flagsPanelCollapsed">
                            <span class="text-xs font-semibold uppercase tracking-wide"
                                  :class="hardFlagCount > 0 ? 'text-red-400' : 'text-amber-400'">
                                Constitutional Flags
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-500">
                                    {{ (props.flags.cap ? 1 : 0) + (props.flags.floor_exceptions?.length ?? 0) + (props.flags.deep_overages?.length ?? 0) + (props.flags.deep_unevenness?.length ?? 0) + (props.flags.incomplete_scopes?.length ?? 0) }}
                                    issue(s)
                                </span>
                                <button v-if="props.maps.length >= 2"
                                        @click.stop="openCompare"
                                        class="px-1.5 py-0.5 rounded text-[10px] border border-indigo-800 bg-indigo-950 text-indigo-400 hover:bg-indigo-900 hover:text-indigo-200 transition-colors">
                                    ⊕ Compare
                                </button>
                                <span class="text-gray-600 text-xs transition-transform"
                                      :class="flagsPanelCollapsed ? '' : 'rotate-90'">›</span>
                            </div>
                        </div>
                        <div v-if="!flagsPanelCollapsed"
                             class="px-3 py-2 space-y-1.5 text-xs max-h-52 overflow-y-auto">
                            <div v-if="props.flags.cap" class="flex items-start gap-2 text-red-400">
                                <span class="shrink-0">⛔</span>
                                <span>
                                    {{ props.flags.cap.delta > 0 ? 'Overcount' : 'Undercount' }}:
                                    {{ props.flags.cap.total.toLocaleString() }} / {{ props.flags.cap.max.toLocaleString() }} seats
                                    ({{ props.flags.cap.delta > 0 ? '+' : '' }}{{ props.flags.cap.delta }})
                                </span>
                            </div>
                            <div v-for="ov in (props.flags.deep_overages ?? [])" :key="'do-' + ov.scope_id"
                                 class="flex items-start gap-2 text-red-400">
                                <span class="shrink-0">⛔</span>
                                <span>
                                    <a @click.prevent="drillTo(ov.scope_id)" href="#" class="underline hover:text-red-300 cursor-pointer">{{ ov.scope_name }}</a>:
                                    districts total {{ ov.actual }} seats (budget {{ ov.budget }}, {{ ov.delta > 0 ? '+' : '' }}{{ ov.delta }})
                                </span>
                            </div>
                            <div v-for="sc in (props.flags.incomplete_scopes ?? [])" :key="'is-' + sc.scope_id"
                                 class="flex items-start gap-2 text-red-400">
                                <span class="shrink-0">⛔</span>
                                <span>
                                    <a @click.prevent="drillTo(sc.scope_id)" href="#" class="underline hover:text-red-300 cursor-pointer">{{ sc.scope_name }}</a>:
                                    {{ sc.unassigned_count }} unassigned jurisdiction{{ sc.unassigned_count === 1 ? '' : 's' }}
                                </span>
                            </div>
                            <div v-for="un in (props.flags.deep_unevenness ?? [])" :key="'du-' + un.scope_id"
                                 class="flex items-start gap-2 text-amber-400">
                                <span class="shrink-0">⚠</span>
                                <span>
                                    <a @click.prevent="drillTo(un.scope_id)" href="#" class="underline hover:text-amber-300 cursor-pointer">{{ un.scope_name }}</a>:
                                    uneven [{{ un.min_seats }}–{{ un.max_seats }}]; ideal {{ un.ideal_range[0] === un.ideal_range[1] ? un.ideal_range[0] : un.ideal_range[0] + '–' + un.ideal_range[1] }} each
                                </span>
                            </div>
                            <div v-if="(props.flags.floor_exceptions ?? []).length > 0"
                                 class="flex items-start gap-2 text-amber-400">
                                <span class="shrink-0">ℹ</span>
                                <span>{{ props.flags.floor_exceptions.length }} floor exception{{ props.flags.floor_exceptions.length === 1 ? '' : 's' }} — below 5.0 fractional, compositing impossible</span>
                            </div>
                        </div><!-- end scrollable flags content -->
                    </div>

                    <!-- Map Quality statistics panel -->
                    <div v-if="props.stats && districtsRef.length > 0"
                         class="mx-2 mt-1 mb-1 rounded border border-cyan-900 bg-gray-900 shrink-0">
                        <div class="flex items-center justify-between px-3 py-1.5 border-b border-gray-800 cursor-pointer select-none"
                             @click="statsPanelCollapsed = !statsPanelCollapsed">
                            <span class="text-xs font-semibold text-cyan-400 uppercase tracking-wide">Map Quality</span>
                            <span class="text-gray-600 text-xs transition-transform"
                                  :class="statsPanelCollapsed ? '' : 'rotate-90'">›</span>
                        </div>
                        <div v-if="!statsPanelCollapsed" class="px-3 py-2 space-y-2.5 text-xs">
                            <!-- Population Equality -->
                            <div v-if="props.stats.population_equality">
                                <div class="relative group inline-flex items-center gap-1 mb-1">
                                    <span class="text-gray-500 text-[10px] uppercase font-semibold">Population Equality</span>
                                    <span class="text-gray-600 normal-case font-normal text-[10px]">({{ props.stats.population_equality.district_count }} districts)</span>
                                    <span class="text-gray-600 text-[9px] cursor-help select-none ml-0.5">?</span>
                                    <div class="pointer-events-none absolute left-0 top-full mt-0.5 z-50 w-56 rounded bg-gray-700 border border-gray-600 p-1.5 text-[10px] text-gray-300 leading-snug hidden group-hover:block shadow-lg">
                                        Measures how evenly each district's population-per-seat matches the ideal quota — the "one person, one vote" standard. Lower deviation means each vote carries more equal weight.
                                        <span class="block mt-1 text-gray-400">(Includes all sub-national districts in this map.)</span>
                                    </div>
                                </div>

                                <!-- Extremes -->
                                <div v-if="props.stats.population_equality.most_over" class="mb-1.5">
                                    <div class="flex items-baseline justify-between gap-2 mb-0.5">
                                        <span class="text-gray-500 text-[10px] uppercase font-semibold">Extremes</span>
                                        <span class="text-gray-400">
                                            Range
                                            <span :class="qualityColor((props.stats.population_equality.range_ratio - 1) * 100, 5, 10)">
                                                {{ props.stats.population_equality.range_ratio }}:1
                                            </span>
                                        </span>
                                    </div>
                                    <div class="space-y-0.5">
                                        <div>
                                            <span class="text-emerald-400">&#9650;</span>
                                            <span class="text-gray-400">Over-rep:</span>
                                            <a @click.prevent="drillTo(props.stats.population_equality.most_over.scope_id)"
                                               href="#" class="underline hover:text-cyan-300 cursor-pointer text-cyan-400">
                                                {{ props.stats.population_equality.most_over.district_label }}
                                            </a>
                                            <span class="text-emerald-400">
                                                (-{{ props.stats.population_equality.most_over.deviation_pct }}%)
                                            </span>
                                        </div>
                                        <div>
                                            <span class="text-red-400">&#9660;</span>
                                            <span class="text-gray-400">Under-rep:</span>
                                            <a @click.prevent="drillTo(props.stats.population_equality.most_under.scope_id)"
                                               href="#" class="underline hover:text-cyan-300 cursor-pointer text-cyan-400">
                                                {{ props.stats.population_equality.most_under.district_label }}
                                            </a>
                                            <span class="text-red-400">
                                                (+{{ props.stats.population_equality.most_under.deviation_pct }}%)
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tier distribution -->
                                <div v-if="props.stats.population_equality.tiers" class="mb-1.5">
                                    <div class="text-gray-500 text-[10px] uppercase mb-0.5 font-semibold">Distribution</div>
                                    <div class="space-y-0.5">
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-emerald-400">&#9632;</span>
                                            <span class="text-gray-400 whitespace-nowrap">Good (&le;5%):</span>
                                            <span class="text-gray-200">
                                                {{ props.stats.population_equality.tiers.good.count }}
                                                ({{ props.stats.population_equality.tiers.good.pct }}%)
                                            </span>
                                            <span class="text-gray-500 ml-auto whitespace-nowrap">
                                                {{ formatPop(props.stats.population_equality.tiers.good.population) }} pop
                                                ({{ pct(props.stats.population_equality.tiers.good.population, props.stats.population_equality.total_population) }})
                                            </span>
                                        </div>
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-amber-400">&#9632;</span>
                                            <span class="text-gray-400 whitespace-nowrap">OK (5-10%):</span>
                                            <span class="text-gray-200">
                                                {{ props.stats.population_equality.tiers.ok.count }}
                                                ({{ props.stats.population_equality.tiers.ok.pct }}%)
                                            </span>
                                            <span class="text-gray-500 ml-auto whitespace-nowrap">
                                                {{ formatPop(props.stats.population_equality.tiers.ok.population) }} pop
                                                ({{ pct(props.stats.population_equality.tiers.ok.population, props.stats.population_equality.total_population) }})
                                            </span>
                                        </div>
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-red-400">&#9632;</span>
                                            <span class="text-gray-400 whitespace-nowrap">Bad (&gt;10%):</span>
                                            <span class="text-gray-200">
                                                {{ props.stats.population_equality.tiers.bad.count }}
                                                ({{ props.stats.population_equality.tiers.bad.pct }}%)
                                            </span>
                                            <span class="text-gray-500 ml-auto whitespace-nowrap">
                                                {{ formatPop(props.stats.population_equality.tiers.bad.population) }} pop
                                                ({{ pct(props.stats.population_equality.tiers.bad.population, props.stats.population_equality.total_population) }})
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Avg deviation -->
                                <div class="grid grid-cols-2 gap-x-3">
                                    <span class="text-gray-400">Avg deviation</span>
                                    <span :class="qualityColor(props.stats.population_equality.avg_deviation_pct, 3, 7)">
                                        {{ props.stats.population_equality.avg_deviation_pct }}%
                                    </span>
                                </div>
                            </div>

                            <!-- Shape Compactness (Convex Hull Ratio) -->
                            <div>
                                <div class="relative group inline-flex items-center gap-1 mb-0.5">
                                    <span class="text-gray-500 text-[10px] uppercase font-semibold">Shape Compactness</span>
                                    <span class="text-gray-600 text-[9px] cursor-help select-none ml-0.5">?</span>
                                    <div class="pointer-events-none absolute left-0 top-full mt-0.5 z-50 w-56 rounded bg-gray-700 border border-gray-600 p-1.5 text-[10px] text-gray-300 leading-snug hidden group-hover:block shadow-lg">
                                        Convex Hull Ratio: district area divided by the area of its convex hull (1.0 = perfectly convex). Measures whether the district's outer boundary is compact or irregular. Unlike Polsby-Popper, this is not affected by coastlines or water bodies — only by the overall shape of the grouped territory.
                                    </div>
                                </div>
                                <div v-if="props.stats.shape_compactness" class="space-y-0.5">
                                    <div class="text-gray-500 text-[10px] mb-0.5">Mean: <span class="text-gray-300">{{ props.stats.shape_compactness.mean }}</span>
                                        &nbsp;·&nbsp; {{ props.stats.shape_compactness.scored }}/{{ props.stats.shape_compactness.total }} scored</div>
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-emerald-400">&#9632;</span>
                                        <span class="text-gray-400 whitespace-nowrap">Compact (&ge;0.70):</span>
                                        <span class="text-gray-200">{{ props.stats.shape_compactness.tiers.good.count }} ({{ props.stats.shape_compactness.tiers.good.pct }}%)</span>
                                        <span class="text-gray-500 ml-auto whitespace-nowrap">
                                            {{ formatPop(props.stats.shape_compactness.tiers.good.population) }} pop
                                            ({{ pct(props.stats.shape_compactness.tiers.good.population, props.stats.shape_compactness.total_population) }})
                                        </span>
                                    </div>
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-amber-400">&#9632;</span>
                                        <span class="text-gray-400 whitespace-nowrap">Moderate (0.50–0.70):</span>
                                        <span class="text-gray-200">{{ props.stats.shape_compactness.tiers.ok.count }} ({{ props.stats.shape_compactness.tiers.ok.pct }}%)</span>
                                        <span class="text-gray-500 ml-auto whitespace-nowrap">
                                            {{ formatPop(props.stats.shape_compactness.tiers.ok.population) }} pop
                                            ({{ pct(props.stats.shape_compactness.tiers.ok.population, props.stats.shape_compactness.total_population) }})
                                        </span>
                                    </div>
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-red-400">&#9632;</span>
                                        <span class="text-gray-400 whitespace-nowrap">Irregular (&lt;0.50):</span>
                                        <span class="text-gray-200">{{ props.stats.shape_compactness.tiers.bad.count }} ({{ props.stats.shape_compactness.tiers.bad.pct }}%)</span>
                                        <span class="text-gray-500 ml-auto whitespace-nowrap">
                                            {{ formatPop(props.stats.shape_compactness.tiers.bad.population) }} pop
                                            ({{ pct(props.stats.shape_compactness.tiers.bad.population, props.stats.shape_compactness.total_population) }})
                                        </span>
                                    </div>
                                </div>
                                <span v-else class="text-gray-600 text-[10px]">— not yet computed</span>
                            </div>

                            <!-- Contiguity -->
                            <div>
                                <div class="relative group inline-flex items-center gap-1 mb-0.5">
                                    <span class="text-gray-500 text-[10px] uppercase font-semibold">Contiguity</span>
                                    <span class="text-gray-600 text-[9px] cursor-help select-none ml-0.5">?</span>
                                    <div class="pointer-events-none absolute left-0 top-full mt-0.5 z-50 w-56 rounded bg-gray-700 border border-gray-600 p-1.5 text-[10px] text-gray-300 leading-snug hidden group-hover:block shadow-lg">
                                        Multi-member districts must be composed of jurisdictions that share land borders — forming a single connected territory. Checked via BFS on spatial adjacency (ST_Intersects). Island jurisdictions with no sibling land border (e.g. Hawaii, Puerto Rico) are never flagged: their geography makes non-contiguity unavoidable and there is no possible redistricting fix. Single-member districts are excluded.
                                    </div>
                                </div>
                                <div v-if="props.stats.contiguity" class="space-y-0.5">
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-emerald-400">&#9632;</span>
                                        <span class="text-gray-400 whitespace-nowrap">Contiguous:</span>
                                        <span class="text-gray-200">
                                            {{ props.stats.contiguity.contiguous_count }}
                                            ({{ pct(props.stats.contiguity.contiguous_count, props.stats.contiguity.checked_count) }})
                                        </span>
                                        <span class="text-gray-500 ml-auto whitespace-nowrap">
                                            {{ formatPop(props.stats.contiguity.contiguous_pop) }} pop
                                            ({{ pct(props.stats.contiguity.contiguous_pop, props.stats.contiguity.contiguous_pop + props.stats.contiguity.non_contiguous_pop + props.stats.contiguity.unchecked_pop) }})
                                        </span>
                                    </div>
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-red-400">&#9632;</span>
                                        <span class="text-gray-400 whitespace-nowrap">Non-contiguous:</span>
                                        <span class="text-gray-200">
                                            {{ props.stats.contiguity.non_contiguous_count }}
                                            ({{ pct(props.stats.contiguity.non_contiguous_count, props.stats.contiguity.checked_count) }})
                                        </span>
                                        <span class="text-gray-500 ml-auto whitespace-nowrap">
                                            {{ formatPop(props.stats.contiguity.non_contiguous_pop) }} pop
                                            ({{ pct(props.stats.contiguity.non_contiguous_pop, props.stats.contiguity.contiguous_pop + props.stats.contiguity.non_contiguous_pop + props.stats.contiguity.unchecked_pop) }})
                                        </span>
                                    </div>
                                    <div v-if="props.stats.contiguity.unchecked_count > 0" class="flex items-baseline gap-1">
                                        <span class="text-gray-600">&#9632;</span>
                                        <span class="text-gray-500 whitespace-nowrap">Not computed:</span>
                                        <span class="text-gray-500">
                                            {{ props.stats.contiguity.unchecked_count }}
                                            ({{ pct(props.stats.contiguity.unchecked_count, props.stats.contiguity.checked_count) }})
                                        </span>
                                        <span class="text-gray-600 ml-auto whitespace-nowrap">
                                            {{ formatPop(props.stats.contiguity.unchecked_pop) }} pop
                                        </span>
                                    </div>
                                </div>
                                <span v-else class="text-gray-600 text-[10px]">— not yet computed</span>
                            </div>

                            <!-- Community Integrity -->
                            <div>
                                <div class="relative group inline-flex items-center gap-1 mb-0.5">
                                    <span class="text-gray-500 text-[10px] uppercase font-semibold">Community Integrity</span>
                                    <span class="text-gray-600 text-[9px] cursor-help select-none ml-0.5">?</span>
                                    <div class="pointer-events-none absolute left-0 top-full mt-0.5 z-50 w-56 rounded bg-gray-700 border border-gray-600 p-1.5 text-[10px] text-gray-300 leading-snug hidden group-hover:block shadow-lg">
                                        Districts drawn along pre-existing administrative boundaries preserve community integrity. The only case requiring manual line-drawing tools is a jurisdiction with 10+ fractional seats and no child subdivisions — all others can be sub-districted along natural administrative borders.
                                    </div>
                                </div>
                                <div v-if="props.stats.community_integrity" class="space-y-0.5">
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-emerald-400">&#9632;</span>
                                        <span class="text-gray-400 whitespace-nowrap">Intact:</span>
                                        <span class="text-gray-200">
                                            {{ props.stats.community_integrity.good_count }}
                                            ({{ pct(props.stats.community_integrity.good_count, props.stats.community_integrity.total_count) }})
                                        </span>
                                        <span class="text-gray-500 ml-auto whitespace-nowrap">
                                            {{ formatPop(props.stats.community_integrity.good_population) }} pop
                                            ({{ pct(props.stats.community_integrity.good_population, props.stats.community_integrity.total_population) }})
                                        </span>
                                    </div>
                                    <div class="flex items-baseline gap-1">
                                        <span :class="(props.stats.community_integrity.total_count - props.stats.community_integrity.good_count) > 0 ? 'text-red-400' : 'text-gray-600'">&#9632;</span>
                                        <span class="text-gray-400 whitespace-nowrap">Needs tools:</span>
                                        <span :class="(props.stats.community_integrity.total_count - props.stats.community_integrity.good_count) > 0 ? 'text-gray-200' : 'text-gray-600'">
                                            {{ props.stats.community_integrity.total_count - props.stats.community_integrity.good_count }}
                                            ({{ pct(props.stats.community_integrity.total_count - props.stats.community_integrity.good_count, props.stats.community_integrity.total_count) }})
                                        </span>
                                        <span class="text-gray-500 ml-auto whitespace-nowrap">
                                            {{ formatPop(props.stats.community_integrity.total_population - props.stats.community_integrity.good_population) }} pop
                                            ({{ pct(props.stats.community_integrity.total_population - props.stats.community_integrity.good_population, props.stats.community_integrity.total_population) }})
                                        </span>
                                    </div>
                                </div>
                                <span v-else class="text-gray-600 text-[10px]">— not yet computed</span>
                            </div>
                        </div>
                    </div>

                    <!-- Map comparison panel -->
                    <div v-if="compareOpen && props.maps.length >= 2"
                         class="mx-2 mt-1 mb-1 rounded border border-indigo-900 bg-gray-900 shrink-0">
                        <div class="flex items-center justify-between px-3 py-1.5 border-b border-gray-800">
                            <span class="text-xs font-semibold text-indigo-400 uppercase tracking-wide">Compare Maps</span>
                            <button @click="compareOpen = false" class="text-gray-600 hover:text-gray-400 text-xs">✕</button>
                        </div>
                        <!-- Map A / Map B selectors -->
                        <div class="px-3 pt-2 pb-1 flex gap-2">
                            <div class="flex-1 min-w-0">
                                <div class="text-[10px] text-gray-500 mb-0.5">Map A</div>
                                <select v-model="compareMapAId"
                                        class="w-full px-1.5 py-1 rounded text-xs bg-gray-800 border border-gray-700 text-gray-200 focus:outline-none focus:border-indigo-600">
                                    <option v-for="m in props.maps" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-[10px] text-gray-500 mb-0.5">Map B</div>
                                <select v-model="compareMapBId"
                                        class="w-full px-1.5 py-1 rounded text-xs bg-gray-800 border border-gray-700 text-gray-200 focus:outline-none focus:border-indigo-600">
                                    <option v-for="m in props.maps" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                            </div>
                        </div>
                        <!-- Flag comparison table -->
                        <div class="px-3 pb-2">
                            <table class="w-full text-[10px]">
                                <thead>
                                    <tr class="text-gray-500">
                                        <th class="text-left py-0.5 font-normal">Flag</th>
                                        <th class="text-right py-0.5 font-normal w-20 truncate">
                                            {{ props.maps.find(m => m.id === compareMapAId)?.name ?? 'A' }}
                                        </th>
                                        <th class="text-right py-0.5 font-normal w-20 truncate">
                                            {{ props.maps.find(m => m.id === compareMapBId)?.name ?? 'B' }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-800">
                                    <tr>
                                        <td class="py-0.5 text-gray-400">Districts</td>
                                        <td class="py-0.5 text-right tabular-nums text-gray-200">
                                            {{ props.maps.find(m => m.id === compareMapAId)?.district_count ?? '—' }}
                                        </td>
                                        <td class="py-0.5 text-right tabular-nums text-gray-200">
                                            {{ props.maps.find(m => m.id === compareMapBId)?.district_count ?? '—' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-0.5 text-red-400">⛔ Cap</td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="props.maps.find(m => m.id === compareMapAId)?.flags?.cap ? 'text-red-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapAId)?.flags?.cap ? 'yes' : '—' }}
                                        </td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="props.maps.find(m => m.id === compareMapBId)?.flags?.cap ? 'text-red-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapBId)?.flags?.cap ? 'yes' : '—' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-0.5 text-red-400">⛔ Overages</td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapAId)?.flags?.overages?.length ?? 0) > 0 ? 'text-red-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapAId)?.flags?.overages?.length || '—' }}
                                        </td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapBId)?.flags?.overages?.length ?? 0) > 0 ? 'text-red-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapBId)?.flags?.overages?.length || '—' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-0.5 text-amber-400">⚠ Unevenness</td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapAId)?.flags?.unevenness?.length ?? 0) > 0 ? 'text-amber-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapAId)?.flags?.unevenness?.length || '—' }}
                                        </td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapBId)?.flags?.unevenness?.length ?? 0) > 0 ? 'text-amber-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapBId)?.flags?.unevenness?.length || '—' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-0.5 text-amber-400">ℹ Floor exceptions</td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapAId)?.flags?.floor_exceptions?.length ?? 0) > 0 ? 'text-amber-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapAId)?.flags?.floor_exceptions?.length || '—' }}
                                        </td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapBId)?.flags?.floor_exceptions?.length ?? 0) > 0 ? 'text-amber-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapBId)?.flags?.floor_exceptions?.length || '—' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-0.5 text-red-400">⛔ Deep overages</td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapAId)?.flags?.deep_overages?.length ?? 0) > 0 ? 'text-red-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapAId)?.flags?.deep_overages?.length || '—' }}
                                        </td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapBId)?.flags?.deep_overages?.length ?? 0) > 0 ? 'text-red-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapBId)?.flags?.deep_overages?.length || '—' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-0.5 text-amber-400">⚠ Deep unevenness</td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapAId)?.flags?.deep_unevenness?.length ?? 0) > 0 ? 'text-amber-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapAId)?.flags?.deep_unevenness?.length || '—' }}
                                        </td>
                                        <td class="py-0.5 text-right tabular-nums"
                                            :class="(props.maps.find(m => m.id === compareMapBId)?.flags?.deep_unevenness?.length ?? 0) > 0 ? 'text-amber-400' : 'text-gray-500'">
                                            {{ props.maps.find(m => m.id === compareMapBId)?.flags?.deep_unevenness?.length || '—' }}
                                        </td>
                                    </tr>
                                    <tr class="border-t border-indigo-900">
                                        <td class="py-0.5 text-gray-300 font-semibold">Total flags</td>
                                        <td class="py-0.5 text-right tabular-nums font-semibold"
                                            :class="countFlags(props.maps.find(m => m.id === compareMapAId)?.flags) > 0 ? 'text-red-300' : 'text-emerald-400'">
                                            {{ countFlags(props.maps.find(m => m.id === compareMapAId)?.flags) || '✓ 0' }}
                                        </td>
                                        <td class="py-0.5 text-right tabular-nums font-semibold"
                                            :class="countFlags(props.maps.find(m => m.id === compareMapBId)?.flags) > 0 ? 'text-red-300' : 'text-emerald-400'">
                                            {{ countFlags(props.maps.find(m => m.id === compareMapBId)?.flags) || '✓ 0' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Mass tools toolbar -->
                    <div class="flex items-center gap-1 px-3 py-2 border-b border-gray-800 bg-gray-900/50 shrink-0">
                        <span class="text-xs text-gray-500 mr-1">Tools:</span>
                        <button @click="openMassTool('reseed')"
                                :disabled="massToolRunning || massJobRunning"
                                class="px-2 py-1 rounded text-xs border transition-colors"
                                :class="massToolRunning || massJobRunning
                                    ? 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'
                                    : massToolPanel === 'reseed'
                                        ? 'bg-indigo-700 border-indigo-500 text-white'
                                        : 'bg-indigo-900 border-indigo-700 text-indigo-300 hover:bg-indigo-800 hover:text-white'">
                            ⚡ Reseed
                        </button>
                        <button @click="openMassTool('clear')"
                                :disabled="massToolRunning || massJobRunning"
                                class="px-2 py-1 rounded text-xs border transition-colors"
                                :class="massToolRunning || massJobRunning
                                    ? 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'
                                    : massToolPanel === 'clear'
                                        ? 'bg-red-700 border-red-500 text-white'
                                        : 'bg-red-900 border-red-800 text-red-300 hover:bg-red-800 hover:text-white'">
                            ✕ Clear
                        </button>
                        <button @click="runRecolor"
                                :disabled="massToolRunning || massJobRunning"
                                class="px-2 py-1 rounded text-xs border transition-colors"
                                :class="massToolRunning || massJobRunning
                                    ? 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'
                                    : 'bg-teal-900 border-teal-700 text-teal-300 hover:bg-teal-800 hover:text-white'">
                            🎨 Recolor
                        </button>
                        <button @click="isDragSelectMode = !isDragSelectMode"
                                :disabled="!editingDistrictId"
                                :title="editingDistrictId
                                    ? 'Drag-select: add unassigned · Shift = include assigned · Ctrl = remove'
                                    : 'Enter edit mode first'"
                                class="px-2 py-1 rounded text-xs border transition-colors"
                                :class="!editingDistrictId
                                    ? 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'
                                    : isDragSelectMode
                                        ? 'bg-blue-700 border-blue-500 text-white'
                                        : 'bg-gray-800 border-gray-700 text-gray-400 hover:text-blue-300 hover:border-blue-700'">
                            ⬚ Select
                        </button>
                    </div>

                    <!-- Scope picker panel -->
                    <div v-if="massToolPanel !== null"
                         class="shrink-0 border-b border-gray-700 bg-gray-900/80 px-3 py-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-white">
                                {{ massToolPanel === 'reseed' ? '⚡ Reseed' : '✕ Clear' }} — choose scope
                            </span>
                            <button @click="closeMassToolPanel" class="text-xs text-gray-500 hover:text-gray-300">✕</button>
                        </div>
                        <div class="flex flex-col gap-1">
                            <button v-for="opt in MASS_SCOPES" :key="opt.key"
                                    @click="massToolScope = opt.key"
                                    class="text-left px-2 py-1.5 rounded text-xs border transition-colors w-full"
                                    :class="massToolScope === opt.key
                                        ? 'bg-gray-700 border-gray-500 text-white'
                                        : 'bg-gray-900 border-gray-700 text-gray-400 hover:text-gray-200 hover:border-gray-600'">
                                <div class="font-medium">{{ opt.label }}</div>
                                <div class="text-gray-500 text-[10px] mt-0.5">{{ opt.desc }}</div>
                                <div v-if="opt.warn" class="text-amber-400 text-[10px] mt-0.5">⚠ May be slow for large legislatures</div>
                            </button>
                        </div>
                        <!-- Unassigned + Clear no-op notice -->
                        <div v-if="massToolPanel === 'clear' && massToolScope && massToolScope.endsWith('_unassigned')"
                             class="mt-2 px-2 py-1.5 rounded bg-amber-950 border border-amber-800 text-xs text-amber-300">
                            Clear only removes existing districts — there are none in unassigned scopes.
                        </div>
                        <div class="flex items-center justify-end gap-2 mt-3">
                            <button @click="closeMassToolPanel"
                                    class="px-3 py-1 rounded text-xs border bg-gray-800 border-gray-700 text-gray-400 hover:text-white transition-colors">
                                Cancel
                            </button>
                            <button @click="runMassTool"
                                    :disabled="!massToolScope || massToolRunning || (massToolPanel === 'clear' && massToolScope && massToolScope.endsWith('_unassigned'))"
                                    class="px-3 py-1 rounded text-xs border transition-colors"
                                    :class="!massToolScope || massToolRunning || (massToolPanel === 'clear' && massToolScope && massToolScope.endsWith('_unassigned'))
                                        ? 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'
                                        : massToolPanel === 'clear'
                                            ? 'bg-red-700 border-red-600 text-white hover:bg-red-600'
                                            : 'bg-indigo-700 border-indigo-600 text-white hover:bg-indigo-600'">
                                Run
                            </button>
                        </div>
                    </div>

                    <!-- Scrollable content area -->
                    <div ref="sidebarListEl" class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden">

                    <!-- Rounding readiness banner -->
                    <div v-if="roundingReady"
                         class="px-3 py-2 bg-emerald-950 border-b border-emerald-800 text-xs text-emerald-300 flex items-center gap-2 shrink-0">
                        <span class="text-emerald-500">✓</span>
                        <span>All compositable jurisdictions assigned — expand subdivisions in the sidebar to continue.</span>
                    </div>

                    <!-- Sort header -->
                    <div class="flex items-center gap-1 px-3 py-1 bg-gray-900/80 border-b border-gray-700 text-xs text-gray-500 shrink-0 sticky top-0 z-10">
                        <span class="w-3 shrink-0"></span><!-- dot spacer -->
                        <button class="flex-1 text-left hover:text-gray-300 truncate" @click="toggleSort('name')">Name{{ sortIndicator('name') }}</button>
                        <button class="w-12 text-right hover:text-gray-300 shrink-0" @click="toggleSort('seats')">Seats{{ sortIndicator('seats') }}</button>
                        <button class="w-20 text-right hover:text-gray-300 shrink-0" @click="toggleSort('pop')">Population{{ sortIndicator('pop') }}</button>
                        <button class="w-12 text-right hover:text-gray-300 shrink-0" @click="toggleSort('frac')">Rep{{ sortIndicator('frac') }}</button>
                        <span class="w-4 shrink-0"></span><!-- chevron spacer -->
                    </div>

                    <!-- Unified district + expandable node list -->
                    <template v-for="(row, ri) in sidebarRows" :key="ri">

                        <!-- Top-level district row (with full expansion) -->
                        <div v-if="row.type === 'district' && !row.nested"
                             :data-district-id="row.district.id"
                             class="border-b border-gray-800">

                            <!-- District header row -->
                            <div class="flex items-center gap-1 px-3 py-2 cursor-pointer select-none"
                                 :style="{ paddingLeft: (12 + row.depth * 14) + 'px' }"
                                 :class="selectedDistrictId === row.district.id ? 'bg-gray-800' : 'hover:bg-gray-800/50'"
                                 @click="toggleSelectDistrict(row.district.id)"
                                 @mouseenter="highlightJids(row.district.members.map(m => m.id))"
                                 @mouseleave="unhighlightJids(row.district.members.map(m => m.id))">
                                <span class="shrink-0 w-2.5 h-2.5 rounded-full mr-0.5" :style="{ background: districtFillColor(row.district.color_index) }"></span>
                                <span class="font-mono text-xs text-gray-100 flex-1 truncate">{{ row.district.name ?? '' }}</span>
                                <span class="text-xs font-semibold w-12 text-right shrink-0"
                                      :class="row.district.floor_override ? 'text-amber-400' : seatClass(row.district.seats)"
                                      :title="row.district.floor_override ? 'Floor override — fractional < 5' : undefined">{{ row.district.seats }}</span>
                                <span class="text-xs text-gray-400 tabular-nums w-20 text-right shrink-0">
                                    {{ (() => {
                                        const dp = row.district.population
                                        if (dp > 0) return formatPop(dp)
                                        const ms = row.district.members.reduce((s, m) => s + m.population, 0)
                                        return ms > 0 ? formatPop(ms) : '—'
                                    })() }}
                                </span>
                                <span class="text-xs text-gray-500 tabular-nums w-12 text-right shrink-0">
                                    {{ (row.district.fractional_seats > 0
                                        ? row.district.fractional_seats
                                        : row.district.members.reduce((s, m) => s + m.fractional_seats, 0)
                                    ).toFixed(2) }}
                                </span>
                                <span class="text-gray-600 text-xs transition-transform shrink-0"
                                      :class="selectedDistrictId === row.district.id ? 'rotate-90' : ''">›</span>
                            </div>

                            <!-- Expanded member list -->
                            <div v-if="selectedDistrictId === row.district.id" class="bg-gray-850">

                                <!-- Action bar (at top so controls are immediately visible) -->
                                <div class="px-3 py-2 flex items-center gap-2 flex-wrap border-b border-gray-700 bg-gray-900/50">
                                    <template v-if="editingDistrictId === row.district.id">
                                        <!-- Seat preview pill -->
                                        <span v-if="pendingAdd.size > 0 || pendingRemove.size > 0"
                                              class="text-xs px-2 py-0.5 rounded-full font-medium"
                                              :class="!pendingValid ? 'bg-red-900 text-red-300'
                                                    : pendingFloor  ? 'bg-orange-900 text-orange-300'
                                                    :                 'bg-emerald-900 text-emerald-300'">
                                            {{ pendingFractionalTotal.toFixed(2) }} → {{ pendingSeats }} seats
                                            <span v-if="!pendingValid"> ✗ exceeds 9</span>
                                            <span v-else-if="pendingFloor"> ⚑ floor</span>
                                        </span>
                                        <!-- Save -->
                                        <button @click="saveDistrictEdit(row.district.id)"
                                                :disabled="savingEdit || (pendingAdd.size === 0 && pendingRemove.size === 0) || !pendingValid"
                                                class="flex-1 px-2 py-1.5 rounded text-xs font-medium border transition-colors"
                                                :class="(pendingAdd.size > 0 || pendingRemove.size > 0) && !savingEdit && pendingValid
                                                    ? 'bg-emerald-700 border-emerald-600 text-white hover:bg-emerald-600'
                                                    : 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'">
                                            {{ savingEdit ? 'Saving…' : `Save (${pendingAdd.size + pendingRemove.size})` }}
                                        </button>
                                        <button @click="cancelEdit"
                                                class="px-2 py-1.5 rounded text-xs border bg-gray-800 border-gray-700 text-gray-400 hover:text-white transition-colors">
                                            Cancel
                                        </button>
                                    </template>
                                    <template v-else>
                                        <button @click.stop="startEdit(row.district.id)"
                                                class="px-2 py-1.5 rounded text-xs border bg-gray-800 border-gray-700 text-gray-400 hover:text-emerald-400 hover:border-emerald-700 transition-colors">
                                            ✏ Edit
                                        </button>
                                        <button v-if="deletingDistrictId !== row.district.id"
                                                @click.stop="deletingDistrictId = row.district.id"
                                                class="px-2 py-1.5 rounded text-xs border bg-gray-800 border-gray-700 text-gray-500 hover:text-red-400 hover:border-red-700 transition-colors">
                                            × Disband
                                        </button>
                                        <template v-else>
                                            <button @click.stop="deleteDistrict(row.district.id)"
                                                    class="flex-1 px-2 py-1.5 rounded text-xs border bg-red-900 border-red-700 text-red-200 hover:bg-red-800 transition-colors">
                                                Confirm disband
                                            </button>
                                            <button @click.stop="deletingDistrictId = null"
                                                    class="px-2 py-1.5 rounded text-xs border bg-gray-800 border-gray-700 text-gray-400 hover:text-white transition-colors">
                                                Cancel
                                            </button>
                                        </template>
                                    </template>
                                </div>

                                <!-- Members -->
                                <div v-for="member in row.district.members" :key="member.id"
                                     class="flex items-center gap-2 px-4 py-1.5 text-xs border-t border-gray-800/60"
                                     :class="pendingRemove.has(member.id) ? 'bg-red-900/20' : ''"
                                     @mouseenter="highlightJids([member.id])"
                                     @mouseleave="unhighlightJids([member.id])">
                                    <span class="text-gray-300 truncate flex-1">{{ member.name }}</span>
                                    <span class="text-gray-500 tabular-nums w-20 text-right shrink-0">{{ member.population > 0 ? formatPop(member.population) : '—' }}</span>
                                    <span class="tabular-nums w-12 text-right shrink-0"
                                          :class="member.fractional_seats > 9 ? 'text-red-400' : 'text-gray-400'">
                                        {{ member.fractional_seats.toFixed(2) }}
                                    </span>
                                    <button v-if="member.fractional_seats >= GIANT_THRESHOLD && member.child_count > 0"
                                            @click.stop="drillTo(member.id)"
                                            class="shrink-0 text-gray-500 hover:text-emerald-400 transition-colors"
                                            title="Drill into sub-districts">▶</button>
                                    <button v-if="editingDistrictId === row.district.id"
                                            @click.stop="togglePendingRemove(member.id)"
                                            class="shrink-0 w-5 h-5 flex items-center justify-center rounded transition-colors"
                                            :class="pendingRemove.has(member.id)
                                                ? 'bg-red-700 text-white'
                                                : 'text-gray-600 hover:text-red-400 hover:bg-red-900/30'"
                                            title="Remove from district">−</button>
                                </div>

                                <!-- Edit-mode pending adds -->
                                <div v-if="editingDistrictId === row.district.id && pendingAdd.size > 0"
                                     class="px-4 py-1 bg-yellow-900/20 border-t border-yellow-800/40">
                                    <div class="text-xs text-yellow-400 font-medium mb-1">Adding ({{ pendingAdd.size }}):</div>
                                    <div v-for="jid in [...pendingAdd]" :key="jid"
                                         class="flex items-center gap-1 text-xs text-yellow-300 py-0.5">
                                        <span class="flex-1 truncate">{{ childrenRef.find(c => c.id === jid)?.name ?? jid }}</span>
                                        <button @click.stop="togglePendingAdd(jid)" class="text-yellow-600 hover:text-yellow-300">×</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Nested district row (from expandedNodes data — expandable to show members) -->
                        <div v-else-if="row.type === 'district' && row.nested"
                             class="border-b border-gray-800 flex items-center gap-1 py-1.5 text-xs cursor-pointer hover:bg-gray-800/30 transition-colors"
                             :style="{ paddingLeft: (12 + row.depth * 14) + 'px' }"
                             @click="toggleNestedDistrict(row.district.id)">
                            <span class="shrink-0 w-2 h-2 rounded-full mr-0.5" :style="{ background: districtFillColor(row.district.color_index) }"></span>
                            <span class="font-mono text-gray-300 flex-1 truncate">{{ row.district.name ?? '' }}</span>
                            <span class="font-semibold w-12 text-right shrink-0"
                                  :class="row.district.floor_override ? 'text-amber-400' : seatClass(row.district.seats)"
                                  :title="row.district.floor_override ? 'Floor override — fractional < 5' : undefined">{{ row.district.seats }}</span>
                            <span class="text-gray-500 tabular-nums w-20 text-right shrink-0">
                                {{ (() => {
                                    const dp = row.district.population
                                    if (dp > 0) return formatPop(dp)
                                    const ms = (row.district.members ?? []).reduce((s, m) => s + m.population, 0)
                                    return ms > 0 ? formatPop(ms) : '—'
                                })() }}
                            </span>
                            <span class="text-gray-600 tabular-nums w-12 text-right shrink-0">{{ Number(row.district.fractional_seats).toFixed(2) }}</span>
                            <span class="text-gray-600 text-xs transition-transform w-4 text-center shrink-0"
                                  :class="expandedNestedDistricts[row.district.id] ? 'rotate-90' : ''">›</span>
                        </div>

                        <!-- Expandable node (formerly "giant") row -->
                        <div v-else-if="row.type === 'giant'"
                             class="border-b border-gray-800 flex items-center gap-1 py-2 text-xs cursor-pointer hover:bg-gray-800/30 transition-colors"
                             :style="{ paddingLeft: (12 + row.depth * 14) + 'px' }"
                             @click="toggleExpand(row.giant.id)"
                             @mouseenter="highlightJids([row.giant.id])"
                             @mouseleave="unhighlightJids([row.giant.id])">
                            <span class="shrink-0 w-2.5 h-2.5 rounded-full bg-gray-600 mr-0.5"></span>
                            <span class="text-gray-400 flex-1 truncate">{{ row.giant.name }}</span>
                            <span v-if="giantFlagType(row.giant.id)"
                                  class="shrink-0 leading-none"
                                  :class="giantFlagType(row.giant.id) === 'overage' ? 'text-red-400' : 'text-amber-400'"
                                  :title="giantFlagType(row.giant.id) === 'overage'
                                      ? 'Child districts exceed apportioned budget'
                                      : 'Districts could be distributed more evenly'">
                                {{ giantFlagType(row.giant.id) === 'overage' ? '⛔' : '⚠' }}
                            </span>
                            <span class="tabular-nums w-12 text-right shrink-0"
                                  :class="seatClass(Math.round(row.giant.fractional_seats))">{{ Math.round(row.giant.fractional_seats) }}</span><!-- Seats -->
                            <span class="text-gray-400 tabular-nums w-20 text-right shrink-0">{{ row.giant.population > 0 ? formatPop(row.giant.population) : '—' }}</span><!-- Population -->
                            <span class="text-gray-600 tabular-nums w-12 text-right shrink-0">{{ row.giant.fractional_seats.toFixed(2) }}</span>
                            <span class="text-gray-500 text-xs transition-transform w-4 text-center shrink-0"
                                  :class="expandedNodes[row.giant.id] ? 'rotate-90' : ''">›</span>
                            <button @click.stop="drillTo(row.giant.id)"
                                    class="shrink-0 text-gray-600 hover:text-emerald-400 transition-colors"
                                    title="Navigate into this scope (change map view)">▶</button>
                        </div>

                        <!-- Loading indicator -->
                        <div v-else-if="row.type === 'loading'"
                             class="border-b border-gray-800 py-2 text-xs text-gray-600 italic"
                             :style="{ paddingLeft: (12 + row.depth * 14) + 'px' }">
                            loading…
                        </div>

                        <!-- Member jurisdiction row (from expanded nested district) -->
                        <div v-else-if="row.type === 'member'"
                             class="border-b border-gray-800/60 flex items-center gap-1 py-1 text-xs"
                             :style="{ paddingLeft: (12 + row.depth * 14) + 'px' }">
                            <span class="shrink-0 w-2 h-2 rounded-full bg-gray-700 mr-0.5"></span>
                            <span class="text-gray-400 flex-1 truncate">{{ row.member.name }}</span>
                            <span class="w-12 shrink-0"></span><!-- Seats placeholder -->
                            <span class="text-gray-600 tabular-nums w-20 text-right shrink-0">
                                {{ row.member.population > 0 ? formatPop(row.member.population) : '—' }}
                            </span>
                            <span class="w-12 shrink-0"></span><!-- Rep placeholder -->
                            <button v-if="row.member.child_count > 0"
                                    @click.stop="drillTo(row.member.id)"
                                    class="w-4 text-center text-gray-600 hover:text-emerald-400 transition-colors shrink-0"
                                    title="Drill into sub-districts">▶</button>
                            <span v-else class="w-4 shrink-0"></span>
                        </div>

                    </template>

                    <!-- ── Unassigned (compositable) ── -->
                    <div v-if="unassignedAssignable.length > 0" class="border-t-2 border-gray-700">
                        <div class="px-3 py-2 flex items-center justify-between border-b border-gray-800 bg-gray-900/70 sticky top-0 z-10">
                            <span class="text-xs font-semibold text-amber-400">
                                Unassigned ({{ unassignedAssignable.length }})
                            </span>
                            <template v-if="editingDistrictId === 'new'">
                                <div class="flex items-center gap-1 flex-wrap justify-end">
                                    <!-- Seat preview pill in new-district mode -->
                                    <span v-if="pendingAdd.size > 0"
                                          class="text-xs px-2 py-0.5 rounded-full font-medium"
                                          :class="!pendingValid ? 'bg-red-900 text-red-300'
                                                : pendingFloor  ? 'bg-orange-900 text-orange-300'
                                                :                 'bg-emerald-900 text-emerald-300'">
                                        {{ pendingFractionalTotal.toFixed(2) }} → {{ pendingSeats }} seats
                                        <span v-if="!pendingValid"> ✗</span>
                                        <span v-else-if="pendingFloor"> ⚑</span>
                                    </span>
                                    <button @click="createDistrictFromPending"
                                            :disabled="pendingAdd.size === 0 || savingEdit || !pendingValid"
                                            class="px-2 py-1 rounded text-xs border transition-colors"
                                            :class="pendingAdd.size > 0 && !savingEdit && pendingValid
                                                ? 'bg-emerald-700 border-emerald-600 text-white hover:bg-emerald-600'
                                                : 'bg-gray-800 border-gray-700 text-gray-600 cursor-not-allowed'">
                                        {{ savingEdit ? 'Creating…' : `Create (${pendingAdd.size})` }}
                                    </button>
                                    <button @click="cancelEdit"
                                            class="px-2 py-1 rounded text-xs border bg-gray-800 border-gray-700 text-gray-400 hover:text-white transition-colors">
                                        ✕
                                    </button>
                                </div>
                            </template>
                            <div v-else class="flex items-center gap-1">
                                <button @click="startNewDistrict"
                                        class="px-2 py-1 rounded text-xs border bg-gray-800 border-gray-700 text-gray-400 hover:text-emerald-400 hover:border-emerald-700 transition-colors">
                                    + New
                                </button>
                            </div>
                        </div>

                        <div v-for="child in unassignedAssignable" :key="child.id"
                             class="flex items-center gap-2 px-3 py-1.5 text-xs border-b border-gray-800/60 transition-colors cursor-pointer"
                             :class="[
                                 pendingAdd.has(child.id) ? 'bg-yellow-900/30' : 'hover:bg-gray-800/40',
                             ]"
                             @click="handleUnassignedClick(child)"
                             @mouseenter="highlightJids([child.id])"
                             @mouseleave="unhighlightJids([child.id])">
                            <span class="shrink-0 w-2 h-2 rounded-full transition-colors"
                                  :class="pendingAdd.has(child.id) ? 'bg-yellow-400' : 'bg-gray-700'"></span>
                            <span class="flex-1 truncate text-gray-300">{{ child.name }}</span>
                            <span class="text-gray-500 tabular-nums shrink-0">{{ formatPop(child.population) }}</span>
                            <span class="tabular-nums shrink-0 text-gray-500">
                                {{ child.fractional_seats.toFixed(2) }}
                            </span>
                        </div>
                    </div>

                    <!-- All assigned notice -->
                    <div v-if="unassignedAssignable.length === 0 && districtsRef.length > 0"
                         class="px-4 py-3 text-xs text-emerald-400 text-center italic">
                        All compositable jurisdictions assigned ✓
                    </div>
                    </div><!-- end scrollable content area -->
                </div><!-- end districts tab flex-col -->

            </aside>

            <!-- ══ Right panel: map ════════════════════════════════════ -->
            <div class="flex-1 relative min-w-0">
                <!-- Map loading indicator — circular progress ring -->
                <div v-if="mapLoading"
                     class="absolute inset-0 z-[1000] flex items-center justify-center pointer-events-none">
                    <div class="relative w-24 h-24 drop-shadow-xl">
                        <svg viewBox="0 0 80 80" class="w-full h-full -rotate-90">
                            <!-- Track -->
                            <circle cx="40" cy="40" r="34" fill="none"
                                    stroke="#1e293b" stroke-width="7"/>
                            <!-- Filling arc — driven by mapLoadPercent (accurate or soft-curve) -->
                            <circle v-if="mapLoadPercent > 0"
                                    cx="40" cy="40" r="34" fill="none"
                                    stroke="#60a5fa" stroke-width="7" stroke-linecap="round"
                                    stroke-dasharray="213.63"
                                    :stroke-dashoffset="213.63 * (1 - mapLoadPercent / 100)"
                                    style="transition: stroke-dashoffset 0.4s ease-out"/>
                            <!-- Spinning arc — shown only before the first byte arrives -->
                            <circle v-else
                                    cx="40" cy="40" r="34" fill="none"
                                    stroke="#60a5fa" stroke-width="7" stroke-linecap="round"
                                    stroke-dasharray="60 153.63"
                                    class="animate-spin"
                                    style="transform-box: fill-box; transform-origin: center; animation-duration: 1.2s"/>
                        </svg>
                        <!-- Centre label -->
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span v-if="mapLoadPercent > 0"
                                  class="text-white text-sm font-bold tabular-nums leading-none">
                                {{ mapLoadPercent }}%
                            </span>
                            <span v-else class="text-blue-400 text-lg leading-none">…</span>
                        </div>
                    </div>
                </div>

                <div id="legislature-map" class="w-full h-full"></div>
                <!-- Rubber-band selection overlay (drag-select mode) -->
                <div ref="rubberBandEl"
                     class="rubber-band"
                     style="display:none; left:0; top:0; width:0; height:0;"></div>

                <!-- Label toggle buttons -->
                <div class="absolute top-3 right-3 z-[1001] flex flex-col gap-1">
                    <button @click="toggleSeatsLabels"
                            class="px-2 py-1 rounded text-xs border transition-colors"
                            :class="showSeatsLabels
                                ? 'bg-indigo-700 border-indigo-500 text-white'
                                : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                            title="Toggle seat count labels">
                        Seats
                    </button>
                    <button @click="toggleMembersLabels"
                            class="px-2 py-1 rounded text-xs border transition-colors"
                            :class="showMembersLabels
                                ? 'bg-emerald-700 border-emerald-500 text-white'
                                : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                            title="Toggle population & fractional seats labels">
                        Pop
                    </button>
                    <button @click="toggleNameLabels"
                            class="px-2 py-1 rounded text-xs border transition-colors"
                            :class="showNameLabels
                                ? 'bg-violet-700 border-violet-500 text-white'
                                : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                            title="Toggle district name labels">
                        Names
                    </button>
                    <button @click="toggleJurisdictionLabels"
                            class="px-2 py-1 rounded text-xs border transition-colors"
                            :class="showJurisdictionLabels
                                ? 'bg-teal-700 border-teal-500 text-white'
                                : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                            title="Toggle jurisdiction name labels">
                        Jurs
                    </button>
                    <button @click="toggleStatsLabels"
                            class="px-2 py-1 rounded text-xs border transition-colors"
                            :class="showStatsLabels
                                ? 'bg-rose-700 border-rose-500 text-white'
                                : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                            title="Toggle per-district quality stats (CHR · contiguity)">
                        Stats
                    </button>
                </div>

                <!-- Edit mode hint overlay -->
                <div v-if="editingDistrictId"
                     class="absolute top-3 left-1/2 -translate-x-1/2 z-[1001] px-4 py-2 rounded text-xs font-medium
                            bg-gray-900/90 border border-gray-700 text-gray-300 pointer-events-none whitespace-nowrap">
                    <template v-if="isDragSelectMode">
                        <span class="text-blue-400">Drag</span> to add ·
                        <span class="text-blue-300">Shift+drag</span> includes assigned ·
                        <span class="text-red-400">Ctrl+drag</span> to remove
                    </template>
                    <template v-else-if="editingDistrictId === 'new'">
                        Click <span class="text-amber-400">unassigned</span> or <span class="text-green-400">green</span> polygons to select · Create from left panel
                    </template>
                    <template v-else>
                        <span class="text-yellow-400">Click unassigned</span> or <span class="text-green-400">green</span> to add ·
                        <span class="text-red-400">Click members</span> to remove · Save from left panel
                    </template>
                </div>

                <!-- Status toast -->
                <transition name="fade">
                    <div v-if="statusMsg"
                         class="absolute bottom-10 left-1/2 -translate-x-1/2 z-[1001] px-4 py-2 rounded text-xs font-medium shadow-lg"
                         :class="statusMsg.type === 'error'
                             ? 'bg-red-900 border border-red-700 text-red-200'
                             : 'bg-emerald-900 border border-emerald-700 text-emerald-200'">
                        {{ statusMsg.text }}
                    </div>
                </transition>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    legislature: Object,  // { id, type_a_seats, type_b_seats, status }
    scope: Object,        // { id, name, adm_level, population }
    scope_seats: Number,  // rounded entitlement at this drill-down level (e.g. 86 for USA)
    ancestors: Array,     // [{ id, name }, ...] root → scope
    children: Array,      // [{ id, name, population, fractional_seats, district_id, district_seats, child_count }]
    districts: Array,     // [{ id, seats, floor_override, status, color_index, district_number, name, members:[{id,name,population,fractional_seats,child_count}] }]
    quota: Number,
    flags: { type: Object, default: () => ({ cap: null, overages: [], unevenness: [], floor_exceptions: [], deep_overages: [], deep_unevenness: [] }) },
    stats: { type: Object, default: null },
    mass_tool_running: { type: Boolean, default: false },
    maps:       { type: Array,  default: () => [] },   // [{ id, name, status, district_count, flags }]
    active_map: { type: Object, default: null },        // the map currently being displayed
})

// ── Constants ─────────────────────────────────────────────────────────────────
// Jurisdictions with fractional_seats >= this threshold cannot be placed in a
// district at this scope level — they must be drilled into (they would round to ≥ 10).
const GIANT_THRESHOLD = 9.5

// 7-color CB-friendly palette — greedy graph coloring guarantees adjacent districts differ
const DISTRICT_COLORS = ['#E69F00', '#56B4E9', '#009E73', '#F0E442', '#0072B2', '#D55E00', '#CC79A7']
function districtFillColor(colorIndex) {
    return DISTRICT_COLORS[(colorIndex ?? 0) % 7]
}

// Mirror PHP makeShortCode() for revealed district name generation.
// Single-member districts use the MEMBER's own code (e.g. Texas → "TEX"), not the parent.
// Multi-member districts use the grandparent code (if depth-2) or parent code (depth-1) + number.
function revealedDistrictName(feat, memberCount) {
    // Mirror PHP makeShortCode():
    //   ADM1: use iso_code directly ("IND", "CHN", "USA")
    //   ADM2+ with hyphen in iso_code: use suffix ("US-CA"→"CA", "CN-HB"→"HB")
    //     (geoBoundaries stores country ISO3 at every level; hyphen = subdivision code)
    //   Fallback: strip generic geographic suffixes ("Province", "Autonomous", "Region"…)
    //     then word-initials ("Uttar Pradesh"→"UP") or first-3 ("Hubei"→"HUB")
    const GENERIC = new Set(['province', 'autonomous', 'region', 'territory', 'oblast', 'krai'])
    function codeFrom(iso, adm, name) {
        if (iso) {
            if (adm === 1) return iso.toUpperCase()  // country code: "IND", "CHN", "USA"
            const pos = iso.lastIndexOf('-')
            if (pos >= 0) {  // subdivision code: "US-CA" → "CA", "CN-HB" → "HB"
                const suffix = iso.slice(pos + 1)
                if (!/^\d+$/.test(suffix)) return suffix.toUpperCase()
            }
        }
        // Name fallback with generic-suffix stripping (fixes CHN "HP" collision)
        const allWords = (name ?? '').trim().split(/\s+/)
        const words    = allWords.filter(w => !GENERIC.has(w.toLowerCase()))
        const use      = words.length ? words : allWords
        return use.length > 1
            ? use.map(w => w[0]).join('').toUpperCase()
            : (use[0] ?? '').slice(0, 3).toUpperCase()
    }
    // Build scope prefix shared by both single- and multi-member districts:
    //   depth-1 (parent=India, grandparent=Earth→root): skip grandparent → ["IND"]
    //   depth-2 (parent=California, grandparent=USA→not root): ["USA", "CAL"]
    const prefix = []
    if (!feat.properties.grandparent_is_root &&
        (feat.properties.grandparent_iso_code || feat.properties.grandparent_name)) {
        prefix.push(codeFrom(
            feat.properties.grandparent_iso_code,
            feat.properties.grandparent_adm_level,
            feat.properties.grandparent_name,
        ))
    }
    prefix.push(codeFrom(
        feat.properties.parent_iso_code,
        feat.properties.parent_adm_level,
        feat.properties.parent_name,
    ))
    if (memberCount === 1) {
        // Single-member: prefix + member's own code → "IND CHH", "BGD RAJ"
        const memberCode = codeFrom(
            feat.properties.member_iso_code,
            feat.properties.member_adm_level,
            feat.properties.member_name,
        )
        return [...prefix, memberCode].join(' ')
    }
    // Multi-member: prefix + sequential number → "IND UP 01", "USA CAL 01"
    const num = String(feat.properties.district_number ?? 0).padStart(2, '0')
    return prefix.join(' ') + ' ' + num
}

// ── Local reactive copies ─────────────────────────────────────────────────────
const childrenRef  = ref(props.children.map(c => ({ ...c })))
const districtsRef = ref(props.districts.map(d => ({
    ...d,
    color_index: d.color_index ?? 0,
    members: d.members.map(m => ({ ...m })),
})))

// ── UI state ──────────────────────────────────────────────────────────────────
const mapLoading          = ref(true)
const mapLoadBytes        = ref(0)      // bytes received so far (children + revealed combined)
const mapTotalBytes       = ref(0)      // total expected bytes (0 = unknown / indeterminate)

// 0–99 while loading (never shows 100 — overlay just disappears on completion).
// Uses accurate ratio when Content-Length is known; otherwise a soft exponential
// curve so the ring visibly fills even when nginx sends chunked gzip (no total).
// Soft curve: 1 MB ≈ 22 %, 4 MB ≈ 63 %, 8.5 MB ≈ 88 % — matches Earth-scope payload.
const mapLoadPercent = computed(() => {
    if (mapTotalBytes.value > 0) {
        return Math.min(Math.round(mapLoadBytes.value / mapTotalBytes.value * 100), 99)
    }
    if (mapLoadBytes.value > 0) {
        return Math.min(Math.round((1 - Math.exp(-mapLoadBytes.value / 4_000_000)) * 99), 99)
    }
    return 0
})
const selectedDistrictId  = ref(null)   // district highlighted on map
const editingDistrictId   = ref(null)   // 'new' | district_id | null
const pendingAdd          = ref(new Set())   // jids staged to add
const pendingRemove       = ref(new Set())   // jids staged to remove
const sidebarListEl       = ref(null)   // ref to scrollable district list container
const isDragSelectMode    = ref(false)  // rubber-band selection active
const rubberBandEl        = ref(null)   // rubber-band overlay div
const savingEdit          = ref(false)
const deletingDistrictId  = ref(null)
const statusMsg           = ref(null)
let   statusTimer         = null
const flagsPanelCollapsed = ref(false)   // constitutional flags panel collapse state
const statsPanelCollapsed = ref(true)   // map quality stats panel (collapsed by default)

// Quality-stat color helpers: green / amber / red based on thresholds
function qualityColor(value, warnAt, badAt) {
    if (value <= warnAt) return 'text-emerald-400'
    if (value <= badAt)  return 'text-amber-400'
    return 'text-red-400'
}
function qualityColorInverse(value, goodAbove, badBelow) {
    if (value >= goodAbove) return 'text-emerald-400'
    if (value >= badBelow)  return 'text-amber-400'
    return 'text-red-400'
}

// ── Map management state ───────────────────────────────────────────────────────
const mapSelectorOpen   = ref(false)
const newMapFormOpen    = ref(false)
const newMapName        = ref('')
const renamingMapId     = ref(null)   // map currently being renamed inline
const renameValue       = ref('')
const deletingMapId     = ref(null)   // map pending delete confirmation
const copyingMapId      = ref(null)   // non-null while copy request is in-flight
const compareOpen       = ref(false)
const compareMapAId     = ref(null)
const compareMapBId     = ref(null)

// Set of parent jurisdiction IDs whose sub-districts are shown on the map
// (the parent polygon is hidden; the sub-district polygons are shown instead)
const brokenGiantIds  = ref(new Set())
const massToolPanel   = ref(null)   // null | 'reseed' | 'clear'
const massToolScope   = ref(null)   // selected operation_scope key
const massToolRunning = ref(false)
// ── Background mass job tracking ───────────────────────────────────────────
// Set to true if a mass operation is in flight (persists across navigation via cache flag).
const massJobRunning    = ref(props.mass_tool_running ?? false)
const recolorProgress   = ref(null)   // { phase, total, started_at } or null
const recolorElapsed    = ref('')
let   massStatusTimer   = null
let   elapsedTimer      = null

function updateElapsed() {
    if (!recolorProgress.value?.started_at) { recolorElapsed.value = ''; return }
    const secs = Math.floor(Date.now() / 1000 - recolorProgress.value.started_at)
    const m = Math.floor(secs / 60), s = secs % 60
    recolorElapsed.value = m > 0 ? `${m}m ${s}s` : `${s}s`
}

function recolorPhaseLabel(phase, total) {
    // Rough ETA for the adjacency phase: empirically ~0.3s per district at Earth scope
    const etaSecs = phase === 'adjacency' ? Math.round(total * 0.3) : 0
    const etaStr  = etaSecs >= 10
        ? ` — est. ${etaSecs >= 60 ? Math.round(etaSecs / 60) + ' min' : etaSecs + 's'}`
        : ''
    if (phase === 'adjacency')  return `Computing adjacency graph (${total} districts${etaStr})…`
    if (phase === 'coloring')   return `Running 7-color algorithm…`
    if (phase === 'persisting') return `Saving colors to database…`
    return 'Finishing up…'
}

function startMassStatusPolling() {
    clearInterval(massStatusTimer)
    clearInterval(elapsedTimer)
    elapsedTimer = setInterval(updateElapsed, 1000)
    massStatusTimer = setInterval(async () => {
        try {
            const res  = await fetch(`/api/legislatures/${props.legislature.id}/mass-status`)
            const data = await res.json()
            if (data.recolor_progress) {
                recolorProgress.value = data.recolor_progress
                updateElapsed()
            }
            if (!data.running) {
                massJobRunning.value  = false
                recolorProgress.value = null
                clearInterval(massStatusTimer)
                clearInterval(elapsedTimer)
                massStatusTimer = null
                elapsedTimer    = null
                // Reload the page to get fresh district data
                router.visit(mapUrl(props.scope.id))
            }
        } catch (_) { /* network hiccup — keep polling */ }
    }, 2500)
}

const MASS_SCOPES = [
    { key: 'map_view_unassigned',              label: 'Unassigned — this scope',               desc: 'Fill gaps only, keep existing districts' },
    { key: 'map_view_all',                     label: 'All — this scope',                      desc: 'Clear and redo all districts at this level' },
    { key: 'map_plus_children_unassigned',     label: 'Unassigned — scope + descendants',      desc: 'Fill gaps here and at each giant descendant scope' },
    { key: 'map_plus_children_all',            label: 'All — scope + descendants',             desc: 'Clear and redo here and at each giant descendant scope' },
    { key: 'giant_descendants_only_unassigned',label: 'Unassigned — large descendants only',   desc: 'Fill gaps at each giant descendant scope (skip this view)' },
    { key: 'giant_descendants_only_all',       label: 'All — large descendants only',          desc: 'Clear and redo only giant descendant scopes (skip this view)' },
]

// Label layer visibility toggles
// Label toggles — persisted in localStorage so they survive Inertia scope navigations
// (router.visit() re-mounts the component; ref(false) would reset on every drill-down).
const LS = {
    seats: 'leg_label_seats',
    pop:   'leg_label_pop',
    names: 'leg_label_names',
    jurs:  'leg_label_jurs',
    stats: 'leg_label_stats',
}
const showSeatsLabels        = ref(localStorage.getItem(LS.seats) === '1')
const showMembersLabels      = ref(localStorage.getItem(LS.pop)   === '1')
const showNameLabels         = ref(localStorage.getItem(LS.names) === '1')
const showStatsLabels        = ref(localStorage.getItem(LS.stats) === '1')

// Single combined district label group — one badge per district, rebuilt on every toggle change.
// Pre-computed label data cached here so rebuilds are fast (no GeoJSON re-iteration).
let districtLabelGroup = null
let _districtLabelData = []   // [{ distId, center, name, seats, popStr, fracStr, color }]

function rebuildDistrictLabelGroup() {
    if (!districtLabelGroup) return
    districtLabelGroup.clearLayers()
    const anyActive = showSeatsLabels.value || showMembersLabels.value || showNameLabels.value || showStatsLabels.value
    if (!anyActive) { districtLabelGroup.remove(); return }
    for (const item of _districtLabelData) {
        const lines = []
        if (showNameLabels.value && item.name)
            lines.push(item.name)
        if (showSeatsLabels.value && item.seats != null)
            lines.push(`<span class="district-label-stat">${item.seats} seats</span>`)
        if (showMembersLabels.value)
            lines.push(`<span class="district-label-stat">${item.popStr} · ${item.fracStr}</span>`)
        if (showStatsLabels.value) {
            const chr = item.chr != null ? `CHR ${item.chr}` : 'CHR —'
            const con = item.isContiguous === true  ? '✓ contig'
                      : item.isContiguous === false ? '✗ split'
                      : '? contig'
            let devStr = ''
            if (item.dev != null) {
                const d     = item.dev
                const color = Math.abs(d) <= 5 ? '#34d399' : Math.abs(d) <= 10 ? '#fbbf24' : '#f87171'
                const sign  = d >= 0 ? '+' : ''
                devStr = ` · <span style="color:${color}">${sign}${d.toFixed(1)}%</span>`
            }
            lines.push(`<span class="district-label-stat">${chr} · ${con}${devStr}</span>`)
        }
        if (!lines.length) continue
        districtLabelGroup.addLayer(L.marker(item.center, {
            icon: L.divIcon({
                className: '',
                html: `<div class="district-label-name" data-district-id="${item.distId}" style="border-color:${item.color}">${lines.join('')}</div>`,
                iconSize: null, iconAnchor: [0, 0],
            }),
            interactive: false,
        }))
    }
    districtLabelGroup.addTo(_map)
}

function toggleSeatsLabels() {
    showSeatsLabels.value = !showSeatsLabels.value
    localStorage.setItem(LS.seats, showSeatsLabels.value ? '1' : '0')
    rebuildDistrictLabelGroup()
}
function toggleMembersLabels() {
    showMembersLabels.value = !showMembersLabels.value
    localStorage.setItem(LS.pop, showMembersLabels.value ? '1' : '0')
    rebuildDistrictLabelGroup()
}
function toggleNameLabels() {
    showNameLabels.value = !showNameLabels.value
    localStorage.setItem(LS.names, showNameLabels.value ? '1' : '0')
    rebuildDistrictLabelGroup()
}
function toggleStatsLabels() {
    showStatsLabels.value = !showStatsLabels.value
    localStorage.setItem(LS.stats, showStatsLabels.value ? '1' : '0')
    rebuildDistrictLabelGroup()
}

// Jurisdiction name label layer — subdued text labels for each child jurisdiction polygon
const showJurisdictionLabels = ref(localStorage.getItem(LS.jurs) === '1')
let   jurisdictionLabelGroup = null

function toggleJurisdictionLabels() {
    showJurisdictionLabels.value = !showJurisdictionLabels.value
    localStorage.setItem(LS.jurs, showJurisdictionLabels.value ? '1' : '0')
    if (!jurisdictionLabelGroup) return
    if (showJurisdictionLabels.value) jurisdictionLabelGroup.addTo(_map)
    else                              jurisdictionLabelGroup.remove()
}

// Reference to the Leaflet map instance (set in onMounted)
let _map = null
let _reinitRevision = 0   // incremented on every reinitMapLayers() call; guards against stale fetches

// Map layer registry  { jid: leafletLayer }
const layerByJid = {}

// ── Computed ──────────────────────────────────────────────────────────────────
const assignedCount = computed(() => childrenRef.value.filter(c => c.district_id).length)

// Compositable: fractional < GIANT_THRESHOLD (can be composited or placed in a district)
const assignableChildren = computed(() =>
    childrenRef.value.filter(c => c.fractional_seats < GIANT_THRESHOLD)
)

// Giants: fractional >= GIANT_THRESHOLD (must drill down — cannot form a district here)
const giantChildren = computed(() =>
    childrenRef.value.filter(c => c.fractional_seats >= GIANT_THRESHOLD)
)

// ── Optimal district configuration hint ───────────────────────────────────────
// Giants (frac >= 9.5) and iteratively-detected oversize singles are excluded
// from the seat budget before computing the optimal split.
// Oversize singles: non-giant children whose individual rounded seat count
// exceeds the max allowed per district in the current optimal split.
// Both categories accumulate into largeSeats+giantSeats shown as a bare total.
const optimalConfig = computed(() => {
    const n = props.scope_seats
    if (!n || n < 5) return null

    const giants     = giantChildren.value
    const giantSeats = giants.reduce((sum, c) => sum + Math.round(c.fractional_seats), 0)
    const giantCount = giants.length

    let pool       = [...assignableChildren.value]
    let poolSeats  = n - giantSeats
    let largeCount = 0
    let largeSeats = 0

    for (let iter = 0; iter < 20; iter++) {
        if (poolSeats < 5 || pool.length === 0) break
        const dMin = Math.ceil(poolSeats / 9)
        const dMax = Math.floor(poolSeats / 5)
        if (dMin > dMax) break

        let best = null
        for (let d = dMin; d <= dMax; d++) {
            const q = Math.floor(poolSeats / d), r = poolSeats % d
            if (!best || r < best.r || (r === best.r && d < best.d)) best = { d, q, r }
        }
        if (!best) break

        const maxAllowed = best.q + (best.r > 0 ? 1 : 0)
        const newLarge   = pool.filter(c => Math.round(c.fractional_seats) > maxAllowed)
        if (newLarge.length === 0) {
            return { ...best, largeCount, largeSeats, giantCount, giantSeats }
        }
        const newLargeSeats = newLarge.reduce((sum, c) => sum + Math.round(c.fractional_seats), 0)
        largeCount += newLarge.length
        largeSeats += newLargeSeats
        poolSeats  -= newLargeSeats
        pool        = pool.filter(c => Math.round(c.fractional_seats) <= maxAllowed)
    }

    // Fallback after loop exhausted
    if (poolSeats >= 5) {
        const dMin = Math.ceil(poolSeats / 9), dMax = Math.floor(poolSeats / 5)
        if (dMin <= dMax) {
            let best = null
            for (let d = dMin; d <= dMax; d++) {
                const q = Math.floor(poolSeats / d), r = poolSeats % d
                if (!best || r < best.r || (r === best.r && d < best.d)) best = { d, q, r }
            }
            if (best) return { ...best, largeCount, largeSeats, giantCount, giantSeats }
        }
    }
    return { d: 0, q: 0, r: 0, largeCount, largeSeats, giantCount, giantSeats }
})

// Shared builder: "(3 × 8) + (1 × 9) + 331 = 358"
// groups = [{ count, seats }, ...] sorted by seats asc
// largeTotal = combined large+giant seat count (shown as bare number, no parens)
// total = the = N terminal value
function buildEquationLabel(groups, largeTotal, total) {
    const parts = groups
        .filter(g => g.count > 0)
        .map(g => `(${g.count} \u00d7 ${g.seats})`)
        .join(' + ')
    const lhs = largeTotal > 0
        ? (parts ? `${parts} + ${largeTotal}` : `${largeTotal}`)
        : parts
    return `${lhs} = ${total}`
}

// Optimal label: always equals scope_seats on the right-hand side
const optimalLabel = computed(() => {
    const cfg = optimalConfig.value
    if (!cfg) return null
    const { d, q, r, largeSeats, giantSeats } = cfg
    const largeTotal = (largeSeats ?? 0) + (giantSeats ?? 0)
    if (d === 0 && largeTotal === 0) return null

    const groups = []
    if (d > 0) {
        if (d - r > 0) groups.push({ count: d - r, seats: q })
        if (r > 0)     groups.push({ count: r,     seats: q + 1 })
    }
    return buildEquationLabel(groups, largeTotal, props.scope_seats)
})

// Current label: same equation format but using actual created districts.
// RHS = createdSeats + largeTotal (grows toward scope_seats as districts are added).
// Always visible once an Optimal exists — never hidden, even when matching optimal
// or when no districts have been created yet (shows 0 in that case).
const currentConfigLabel = computed(() => {
    const cfg = optimalConfig.value
    if (!cfg) return null

    // Seats committed inside giants by drilling down (child_assigned_seats is injected by PHP).
    // We only count giants because assignable children are captured via districtsRef instead.
    // West Bengal (a giant with 0 sub-districts in this map) contributes 0 — correct.
    const committedGiantSeats = giantChildren.value
        .reduce((s, c) => s + (c.child_assigned_seats ?? 0), 0)

    const createdSeats = districtsRef.value.reduce((s, d) => s + d.seats, 0)
    const total = createdSeats + committedGiantSeats

    if (total === 0) return '0 = 0'

    // Build seat-count groups from directly created normal districts
    const countMap = {}
    for (const d of districtsRef.value) countMap[d.seats] = (countMap[d.seats] || 0) + 1
    const groups = Object.entries(countMap)
        .sort((a, b) => Number(a[0]) - Number(b[0]))
        .map(([seats, count]) => ({ count, seats: Number(seats) }))

    // committedGiantSeats rendered as a bare number (same style as Optimal's largeTotal)
    return buildEquationLabel(groups, committedGiantSeats, total)
})

// ── Constitutional validation flags ───────────────────────────────────────────
const flagIndex = computed(() => ({
    overageIds: new Set((props.flags?.deep_overages  ?? []).map(o => o.scope_id)),
    unevenIds:  new Set((props.flags?.deep_unevenness ?? []).map(u => u.scope_id)),
}))

const hasAnyFlag = computed(() =>
    !!(props.flags?.cap
    || props.flags?.floor_exceptions?.length
    || props.flags?.deep_overages?.length
    || props.flags?.deep_unevenness?.length
    || props.flags?.incomplete_scopes?.length)
)

const hardFlagCount = computed(() =>
    (props.flags?.cap ? 1 : 0)
    + (props.flags?.deep_overages?.length ?? 0)
    + (props.flags?.incomplete_scopes?.length ?? 0)
)

function giantFlagType(id) {
    if (flagIndex.value.overageIds.has(id)) return 'overage'
    if (flagIndex.value.unevenIds.has(id))  return 'uneven'
    return null
}

// ── Inline giant expansion tree ───────────────────────────────────────────────
const expandedNodes           = reactive({})   // scopeId → true/false (giant nodes)
const nestedData              = reactive({})   // scopeId → { districts: [], giants: [] }
const loadingNodes            = reactive({})   // scopeId → true/false
const expandedNestedDistricts = reactive({})   // district_id → true/false (nested district member expansion)

async function toggleExpand(scopeId) {
    if (expandedNodes[scopeId]) {
        expandedNodes[scopeId] = false
        return
    }
    expandedNodes[scopeId] = true
    if (nestedData[scopeId]) return   // already loaded
    loadingNodes[scopeId] = true
    try {
        const res  = await fetch(`/api/legislatures/${props.legislature.id}/districts-at?scope=${scopeId}&map=${props.active_map?.id ?? ''}`)
        if (!res.ok) throw new Error(`HTTP ${res.status}`)
        const data = await res.json()
        nestedData[scopeId] = data
    } catch (err) {
        console.error('toggleExpand failed for scope', scopeId, err)
        // Collapse the node so the row doesn't freeze in "loading…" state
        expandedNodes[scopeId] = false
    } finally {
        loadingNodes[scopeId] = false
    }
}

function toggleNestedDistrict(id) {
    expandedNestedDistricts[id] = !expandedNestedDistricts[id]
}

// Flat list of rows for the sidebar: districts (top-level + nested), member rows, and expandable giant nodes.
// Each row: { type: 'district'|'member'|'giant'|'loading', depth, district?, member?, giant?, nested? }
const sidebarRows = computed(() => {
    const rows = []

    function pushMemberRows(members, depth) {
        for (const m of members) {
            rows.push({ type: 'member', member: m, depth })
        }
    }

    function pushDistricts(districts, depth, nested = false) {
        for (const d of districts) {
            rows.push({ type: 'district', district: d, depth, nested })
            // If this is a nested district and it's expanded, show its members below
            if (nested && expandedNestedDistricts[d.id] && d.members?.length > 0) {
                pushMemberRows(d.members, depth + 1)
            }
        }
    }

    function pushGiants(giants, depth) {
        for (const g of giants) {
            rows.push({ type: 'giant', giant: g, depth })
            if (expandedNodes[g.id]) {
                if (loadingNodes[g.id]) {
                    rows.push({ type: 'loading', depth: depth + 1 })
                } else {
                    const sub = nestedData[g.id]
                    if (sub) {
                        // Nested districts sorted by seats desc by default
                        const subDistricts = Array.isArray(sub.districts) ? sub.districts : []
                        const subGiants    = Array.isArray(sub.giants)    ? sub.giants    : []
                        const sorted = [...subDistricts].sort((a, b) => b.seats - a.seats)
                        pushDistricts(sorted, depth + 1, true)
                        pushGiants(subGiants, depth + 1)
                    }
                }
            }
        }
    }

    // Build a merged, sorted top-level list of districts + giants.
    // Each entry: { kind: 'district'|'giant', item }
    const dir = sortDir.value === 'asc' ? 1 : -1
    const topLevel = [
        ...districtsRef.value.map(d => ({ kind: 'district', item: d })),
        ...giantChildren.value.map(g => ({ kind: 'giant',    item: g })),
    ].sort((a, b) => {
        function val(entry) {
            const { kind, item } = entry
            if (sortKey.value === 'name') return item.name ?? ''
            if (sortKey.value === 'seats') {
                return kind === 'district'
                    ? item.seats
                    : Math.round(item.fractional_seats)
            }
            if (sortKey.value === 'pop') {
                return kind === 'district'
                    ? (item.population > 0 ? item.population : item.members.reduce((s, m) => s + m.population, 0))
                    : item.population
            }
            // frac
            return kind === 'district'
                ? (item.fractional_seats > 0 ? item.fractional_seats : item.members.reduce((s, m) => s + m.fractional_seats, 0))
                : item.fractional_seats
        }
        const va = val(a), vb = val(b)
        if (sortKey.value === 'name') return dir * va.localeCompare(vb)
        return dir * (va - vb)
    })

    for (const entry of topLevel) {
        if (entry.kind === 'district') {
            pushDistricts([entry.item], 0, false)
        } else {
            pushGiants([entry.item], 0)
        }
    }
    return rows
})

// Unassigned compositable (not yet in any district)
const unassignedAssignable = computed(() =>
    assignableChildren.value.filter(c => !c.district_id)
)

// ── Sortable district list ────────────────────────────────────────────────────
const sortKey = ref('seats')   // 'name' | 'seats' | 'pop' | 'frac'
const sortDir = ref('desc')    // 'asc' | 'desc'

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDir.value = key === 'name' ? 'asc' : 'desc'
    }
}

function sortIndicator(key) {
    if (sortKey.value !== key) return ''
    return sortDir.value === 'asc' ? ' ↑' : ' ↓'
}

const isRootScope = computed(() =>
    props.scope.id === props.legislature.root_jurisdiction_id
)

// First child of root for district naming: at depth ≥ 2, ancestors[1] is the first child
// of root (e.g. USA when drilling into California). At root or depth-1 this equals scope.id.
const effectiveScopeId = computed(() =>
    isRootScope.value
        ? props.scope.id
        : (props.ancestors[1]?.id ?? props.scope.id)
)

// ── Pending seat preview (for new-district and edit modes) ────────────────────
const pendingFractionalTotal = computed(() => {
    let total = 0
    // Add pending additions
    for (const jid of pendingAdd.value) {
        const c = childrenRef.value.find(x => x.id === jid)
        if (c) total += c.fractional_seats
    }
    // When editing existing district: add existing members (minus pending removes)
    if (editingDistrictId.value && editingDistrictId.value !== 'new') {
        const editDist = districtsRef.value.find(d => d.id === editingDistrictId.value)
        for (const m of editDist?.members ?? []) {
            if (!pendingRemove.value.has(m.id)) total += m.fractional_seats
        }
    }
    return total
})
const pendingSeats = computed(() => Math.max(5, Math.round(pendingFractionalTotal.value)))
// Valid: composite sum < GIANT_THRESHOLD (would round to ≤ 9 seats)
const pendingValid = computed(() =>
    pendingAdd.value.size === 0 && pendingRemove.value.size === 0
        ? true
        : pendingFractionalTotal.value < GIANT_THRESHOLD
)
// Floor override needed: total < 5.0 frac but we allow it (unavoidable cases)
const pendingFloor = computed(() =>
    (pendingAdd.value.size > 0 || pendingRemove.value.size > 0) &&
    pendingFractionalTotal.value < 5.0
)

// ── Rounding readiness ────────────────────────────────────────────────────────
// True when all compositable jurisdictions are assigned AND all districts have valid fracs.
// Signals the user they can now drill into giants.
const roundingReady = computed(() =>
    unassignedAssignable.value.length === 0 &&
    districtsRef.value.length > 0 &&
    districtsRef.value.every(d =>
        d.members.reduce((s, m) => s + m.fractional_seats, 0) >= 5.0
    )
)

// ── Helpers ───────────────────────────────────────────────────────────────────
function seatColor(seats) {
    if (!seats) return '#64748b'
    if (seats <= 5) return '#93c5fd'   // blue-300
    if (seats <= 7) return '#34d399'   // emerald-400
    return '#f59e0b'                   // amber-400
}
function seatClass(seats) {
    if (!seats) return 'text-gray-500'
    if (seats <= 5) return 'text-blue-400'
    if (seats <= 7) return 'text-emerald-400'
    return 'text-amber-400'
}
function formatPop(n) {
    if (n >= 1_000_000_000) return (n / 1_000_000_000).toFixed(1) + 'B'
    if (n >= 1_000_000)     return (n / 1_000_000).toFixed(1) + 'M'
    if (n >= 1_000)         return (n / 1_000).toFixed(0) + 'K'
    return n.toLocaleString()
}
function pct(n, total, decimals = 1) {
    if (!total || total <= 0) return '0%'
    return (n / total * 100).toFixed(decimals) + '%'
}
function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}
function showStatus(type, text, ms = 3500) {
    clearTimeout(statusTimer)
    statusMsg.value = { type, text }
    statusTimer = setTimeout(() => { statusMsg.value = null }, ms)
}

// ── Map management helpers ─────────────────────────────────────────────────────
function mapUrl(scopeId, mapId) {
    const mid = mapId !== undefined ? mapId : props.active_map?.id
    let url = `/legislatures/${props.legislature.id}?scope=${scopeId}`
    if (mid) url += `&map=${mid}`
    return url
}

function countFlags(flags) {
    if (!flags) return 0
    return (flags.cap ? 1 : 0)
        + (flags.floor_exceptions?.length ?? 0)
        + (flags.deep_overages?.length ?? 0)
        + (flags.deep_unevenness?.length ?? 0)
        + (flags.incomplete_scopes?.length ?? 0)
}

function switchMap(mapId) {
    mapSelectorOpen.value = false
    router.visit(mapUrl(props.scope.id, mapId))
}

async function submitNewMap() {
    const name = newMapName.value.trim()
    if (!name) return
    try {
        const resp = await fetch(`/api/legislatures/${props.legislature.id}/maps`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body:    JSON.stringify({ name }),
        })
        const data = await resp.json()
        if (!resp.ok) { showStatus('error', data.error ?? 'Failed to create map'); return }
        newMapFormOpen.value = false
        newMapName.value     = ''
        router.visit(mapUrl(props.scope.id, data.id))
    } catch (e) {
        console.error('createMap:', e)
        showStatus('error', 'Network error')
    }
}

async function activateCurrentMap() {
    if (!props.active_map?.id) return
    if (!confirm(`Activate "${props.active_map.name}" as the official apportionment?`)) return
    try {
        const resp = await fetch(
            `/api/legislatures/${props.legislature.id}/maps/${props.active_map.id}/activate`,
            { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf() } }
        )
        if (!resp.ok) { showStatus('error', 'Failed to activate map'); return }
        router.visit(mapUrl(props.scope.id))
    } catch (e) {
        console.error('activateMap:', e)
        showStatus('error', 'Network error')
    }
}

function openCompare() {
    // Default: compare active map vs the first other map
    compareMapAId.value = props.active_map?.id ?? props.maps[0]?.id ?? null
    compareMapBId.value = props.maps.find(m => m.id !== compareMapAId.value)?.id ?? null
    compareOpen.value   = true
}

function startRename(map) {
    renamingMapId.value = map.id
    renameValue.value   = map.name
    mapSelectorOpen.value = false
}

async function submitRename(mapId) {
    const name = renameValue.value.trim()
    if (!name) { renamingMapId.value = null; return }
    try {
        const resp = await fetch(
            `/api/legislatures/${props.legislature.id}/maps/${mapId}`,
            {
                method:  'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body:    JSON.stringify({ name }),
            }
        )
        if (!resp.ok) { showStatus('error', 'Failed to rename map'); return }
        renamingMapId.value = null
        router.visit(mapUrl(props.scope.id))   // reload to refresh map list
    } catch (e) {
        console.error('renameMap:', e)
        showStatus('error', 'Network error')
    }
}

function cancelRename() {
    renamingMapId.value = null
    renameValue.value   = ''
}

async function duplicateMap(mapId) {
    copyingMapId.value = mapId
    const srcMap = props.maps.find(m => m.id === mapId)
    const name   = 'Copy of ' + (srcMap?.name ?? 'Map')
    try {
        const resp = await fetch(
            `/api/legislatures/${props.legislature.id}/maps/${mapId}/copy`,
            {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body:    JSON.stringify({ name }),
            }
        )
        const data = await resp.json()
        if (!resp.ok) { showStatus('error', data.error ?? 'Failed to copy map'); return }
        router.visit(mapUrl(props.scope.id))
    } catch (e) {
        console.error('duplicateMap:', e)
        showStatus('error', 'Network error')
    } finally {
        copyingMapId.value = null
    }
}

async function confirmDeleteMap(mapId) {
    deletingMapId.value = null
    try {
        const resp = await fetch(
            `/api/legislatures/${props.legislature.id}/maps/${mapId}`,
            { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf() } }
        )
        const data = await resp.json()
        if (!resp.ok) { showStatus('error', data.error ?? 'Failed to delete map'); return }
        // If we deleted the map we're currently viewing, navigate to root (no map param)
        if (mapId === props.active_map?.id) {
            router.visit(`/legislatures/${props.legislature.id}`)
        } else {
            router.visit(mapUrl(props.scope.id))
        }
    } catch (e) {
        console.error('deleteMap:', e)
        showStatus('error', 'Network error')
    }
}

// ── Map layer styling ─────────────────────────────────────────────────────────
const STYLE_UNASSIGNED  = { fillColor: '#94a3b8', fillOpacity: 0.55, color: '#475569', weight: 1, opacity: 1 }
const STYLE_GIANT       = { fillColor: '#7f1d1d', fillOpacity: 0.45, color: '#ef4444', weight: 2, opacity: 1 }
const STYLE_YELLOW      = { fillColor: '#fbbf24', fillOpacity: 0.70, color: '#78350f', weight: 2, opacity: 1 }
const STYLE_RED         = { fillColor: '#f87171', fillOpacity: 0.65, color: '#7f1d1d', weight: 2, opacity: 1 }
const STYLE_GREEN       = { fillColor: '#4ade80', fillOpacity: 0.60, color: '#14532d', weight: 2, opacity: 1 }
const STYLE_HIGHLIGHT   = (fillColor) => ({ fillColor, fillOpacity: 0.65, color: '#fbbf24', weight: 3, opacity: 1 })
const STYLE_NORMAL      = (colorIndex) => ({
    fillColor:   districtFillColor(colorIndex),
    fillOpacity: 0.65,
    color:       '#0f172a',
    weight:      1,
    opacity:     1,
})
// Stealable polygon in edit mode — green-tinted (other district's member, can be reassigned)
const STYLE_STEAL = { fillColor: '#86efac', fillOpacity: 0.30, color: '#22c55e', weight: 1.5, opacity: 0.8 }

function getLayerStyle(jid) {
    const child = childrenRef.value.find(c => c.id === jid)
    if (!child) return STYLE_UNASSIGNED

    // Large jurisdictions that can't be directly assigned — hide if broken into sub-districts,
    // otherwise show same grey as ordinary unassigned polygons.
    if (child.fractional_seats >= GIANT_THRESHOLD) {
        if (brokenGiantIds.value.has(child.id)) {
            return { fillOpacity: 0, opacity: 0, weight: 0 }
        }
        if (!child.district_id) return STYLE_UNASSIGNED
    }

    // Edit existing district mode
    if (editingDistrictId.value && editingDistrictId.value !== 'new') {
        const editDist = districtsRef.value.find(d => d.id === editingDistrictId.value)
        const isMember = editDist?.members.some(m => m.id === jid)
        if (pendingRemove.value.has(jid)) return STYLE_RED
        if (pendingAdd.value.has(jid))    return STYLE_YELLOW
        if (isMember)                     return STYLE_GREEN
        // Belongs to a different district → stealable (green-tinted, clickable to reassign)
        if (child.district_id && child.district_id !== editingDistrictId.value) return STYLE_STEAL
        // Unassigned: fall through to STYLE_UNASSIGNED at bottom
    }

    // New district mode — pending selections and stealable polygons
    if (editingDistrictId.value === 'new') {
        if (pendingAdd.value.has(jid)) return STYLE_YELLOW
        if (child.district_id)         return STYLE_STEAL   // stealable from other district
        // Unassigned falls through → STYLE_UNASSIGNED
    }

    // Browse — highlight selected district members
    if (selectedDistrictId.value) {
        const selDist = districtsRef.value.find(d => d.id === selectedDistrictId.value)
        if (selDist?.members.some(m => m.id === jid)) {
            return STYLE_HIGHLIGHT(districtFillColor(selDist.color_index))
        }
    }

    // Default
    if (!child.district_id) return STYLE_UNASSIGNED
    const dist = districtsRef.value.find(d => d.id === child.district_id)
    return STYLE_NORMAL(dist?.color_index ?? 0)
}

function restyleLayer(jid) {
    const layer = layerByJid[jid]
    if (layer) layer.setStyle(getLayerStyle(jid))
}
function restyleAll() {
    for (const jid of Object.keys(layerByJid)) restyleLayer(jid)
}

// ── Sidebar hover → map highlight ────────────────────────────────────────────
function highlightJids(jids) {
    for (const jid of jids) {
        const layer = layerByJid[jid]
        if (!layer) continue
        const style = getLayerStyle(jid)
        layer.setStyle({
            ...style,
            fillOpacity: Math.min((style.fillOpacity ?? 0.65) + 0.2, 0.92),
            weight: Math.max((style.weight ?? 1) + 1, 2),
            color: '#fbbf24',
        })
        layer.bringToFront()
    }
}
function unhighlightJids(jids) {
    for (const jid of jids) restyleLayer(jid)
}

// ── Selection & edit state management ────────────────────────────────────────

/** Pan/zoom the map to fit a district's member polygons. */
function panMapToDistrict(districtId) {
    const dist = districtsRef.value.find(x => x.id === districtId)
    if (!dist || !_map) return
    const bounds = L.latLngBounds([])
    for (const m of dist.members) {
        const layer = layerByJid[m.id]
        if (layer) try { bounds.extend(layer.getBounds()) } catch (_) {}
    }
    if (bounds.isValid()) _map.fitBounds(bounds, { padding: [50, 50], maxZoom: 8 })
}

/** Scroll the sidebar district list to the row for the given districtId. */
function scrollToSidebarRow(districtId) {
    if (!sidebarListEl.value) return
    const el = sidebarListEl.value.querySelector(`[data-district-id="${districtId}"]`)
    if (!el) return
    // 'start' ensures the row anchors to the top of the scroll container so the
    // district header and its expanded members are all visible, not just the header.
    el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

/**
 * Toggle district selection.
 * fromMap=true  → called from a map polygon click: scroll sidebar, skip pan.
 * fromMap=false → called from a sidebar row click: pan map, skip sidebar scroll.
 */
function toggleSelectDistrict(districtId, fromMap = false) {
    if (editingDistrictId.value) return
    const prev = selectedDistrictId.value
    selectedDistrictId.value = prev === districtId ? null : districtId
    restyleAll()
    if (selectedDistrictId.value) {
        if (fromMap) scrollToSidebarRow(districtId)
        else         panMapToDistrict(districtId)
    }
}

function startEdit(districtId) {
    cancelEdit()
    selectedDistrictId.value = districtId
    editingDistrictId.value  = districtId
    restyleAll()
}

function startNewDistrict() {
    cancelEdit()
    selectedDistrictId.value = null
    editingDistrictId.value  = 'new'
    restyleAll()
}

function cancelEdit() {
    const wasEditing = editingDistrictId.value
    editingDistrictId.value  = null
    pendingAdd.value         = new Set()
    pendingRemove.value      = new Set()
    deletingDistrictId.value = null
    isDragSelectMode.value   = false
    if (wasEditing) restyleAll()
}

function togglePendingAdd(jid) {
    const s = new Set(pendingAdd.value)
    if (s.has(jid)) s.delete(jid)
    else            s.add(jid)
    pendingAdd.value = s
    restyleLayer(jid)
}

function togglePendingRemove(jid) {
    const s = new Set(pendingRemove.value)
    if (s.has(jid)) s.delete(jid)
    else            s.add(jid)
    pendingRemove.value = s
    restyleLayer(jid)
}

// ── Unassigned click handler ──────────────────────────────────────────────────
function handleUnassignedClick(child) {
    // Only compositable (non-giant) jurisdictions can be added
    if (child.fractional_seats >= GIANT_THRESHOLD) return
    if (!editingDistrictId.value) {
        // Browse mode: auto-start a new district and pre-select this jurisdiction
        startNewDistrict()
    }
    togglePendingAdd(child.id)
}

// ── Navigation ────────────────────────────────────────────────────────────────
function drillTo(jid) {
    router.visit(mapUrl(jid))
}

// ── District CRUD API calls ───────────────────────────────────────────────────

async function createDistrictFromPending() {
    if (pendingAdd.value.size === 0 || savingEdit.value || !pendingValid.value) return
    savingEdit.value = true
    const jids = [...pendingAdd.value]
    try {
        const resp = await fetch(`/api/legislatures/${props.legislature.id}/districts`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body:    JSON.stringify({
                jurisdiction_ids: jids,
                scope_id:        props.scope.id,         // actual scope (validation)
                label_scope_id:  effectiveScopeId.value, // first child of root (naming)
                map_id:          props.active_map?.id ?? null,
            }),
        })
        const data = await resp.json()
        if (!resp.ok) { showStatus('error', data.error ?? 'Failed to create district'); return }

        const d = data.district
        const members = jids.map(jid => {
            const c = childrenRef.value.find(x => x.id === jid)
            return {
                id: jid, name: c?.name ?? '', population: c?.population ?? 0,
                fractional_seats: c?.fractional_seats ?? 0, child_count: c?.child_count ?? 0,
            }
        })
        // Strip stolen jids from any source district, update their seat counts, then add the new district
        const affectedMap  = Object.fromEntries((data.affected_districts ?? []).map(a => [a.id, a]))
        const colorUpdates = data.color_updates ?? {}
        districtsRef.value = [
            ...districtsRef.value.map(existing => {
                const hasStolenMember = jids.some(jid => existing.members.some(m => m.id === jid))
                const affUpdate = affectedMap[existing.id]
                // Always apply server-recomputed color_index (neighbor colors change on every new district)
                const newColor = colorUpdates[existing.id] ?? existing.color_index
                if (hasStolenMember) {
                    return {
                        ...existing,
                        members: existing.members.filter(m => !jids.includes(m.id)),
                        color_index: newColor,
                        ...(affUpdate ? { seats: affUpdate.seats, floor_override: affUpdate.floor_override } : {}),
                    }
                }
                return { ...existing, color_index: newColor }
            }),
            {
                id: d.id, seats: d.seats, floor_override: d.floor_override,
                color_index: d.color_index ?? 0,
                status: d.status,
                district_number: d.district_number ?? 0,
                name: d.name ?? '',
                members,
            },
        ]
        childrenRef.value = childrenRef.value.map(c =>
            jids.includes(c.id) ? { ...c, district_id: d.id, district_seats: d.seats } : c
        )
        cancelEdit()
        selectedDistrictId.value = d.id
        restyleAll()
        showStatus('success', `District created: ${d.seats} seats · ${jids.length} jurisdictions`)
    } catch (e) {
        console.error('createDistrict:', e)
        showStatus('error', 'Network error')
    } finally {
        savingEdit.value = false
    }
}

async function saveDistrictEdit(districtId) {
    if (savingEdit.value || !pendingValid.value) return
    const add    = [...pendingAdd.value]
    const remove = [...pendingRemove.value]
    if (add.length === 0 && remove.length === 0) { cancelEdit(); return }

    savingEdit.value = true
    try {
        const resp = await fetch(
            `/api/legislatures/${props.legislature.id}/districts/${districtId}/members`,
            {
                method:  'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body:    JSON.stringify({ add, remove, label_scope_id: effectiveScopeId.value, map_id: props.active_map?.id ?? null }),
            }
        )
        const data = await resp.json()
        if (!resp.ok) { showStatus('error', data.error ?? 'Failed to save'); return }

        const updated = data.district
        const affectedMap2 = Object.fromEntries((data.affected_districts ?? []).map(a => [a.id, a]))
        districtsRef.value = districtsRef.value.map(d => {
            if (d.id === districtId) {
                // Update the edited district — add new members, remove old ones
                let members = d.members.filter(m => !remove.includes(m.id))
                for (const jid of add) {
                    const c = childrenRef.value.find(x => x.id === jid)
                    if (c) members.push({ id: jid, name: c.name, population: c.population,
                        fractional_seats: c.fractional_seats, child_count: c.child_count })
                }
                return { ...d, seats: updated.seats, floor_override: updated.floor_override,
                    color_index: updated.color_index ?? d.color_index,
                    name: updated.name ?? d.name, members }
            }
            // Strip stolen jids from source districts and update their seat counts
            const affUpdate2 = affectedMap2[d.id]
            if (add.length > 0 && d.members.some(m => add.includes(m.id))) {
                return {
                    ...d,
                    members: d.members.filter(m => !add.includes(m.id)),
                    ...(affUpdate2 ? { seats: affUpdate2.seats, floor_override: affUpdate2.floor_override, color_index: affUpdate2.color_index } : {}),
                }
            }
            if (affUpdate2) {
                return { ...d, seats: affUpdate2.seats, floor_override: affUpdate2.floor_override, color_index: affUpdate2.color_index }
            }
            return d
        })
        childrenRef.value = childrenRef.value.map(c => {
            if (remove.includes(c.id)) return { ...c, district_id: null, district_seats: null }
            if (add.includes(c.id))    return { ...c, district_id: districtId, district_seats: updated.seats }
            if (c.district_id === districtId) return { ...c, district_seats: updated.seats }
            return c
        })
        cancelEdit()
        selectedDistrictId.value = districtId
        restyleAll()
        showStatus('success', `District updated: ${updated.seats} seats`)
    } catch (e) {
        console.error('saveDistrictEdit:', e)
        showStatus('error', 'Network error')
    } finally {
        savingEdit.value = false
    }
}

async function deleteDistrict(districtId) {
    try {
        const resp = await fetch(
            `/api/legislatures/${props.legislature.id}/districts/${districtId}`,
            { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf() } }
        )
        if (!resp.ok) { showStatus('error', 'Failed to disband district'); return }

        const memberIds = districtsRef.value.find(d => d.id === districtId)?.members.map(m => m.id) ?? []
        districtsRef.value = districtsRef.value.filter(d => d.id !== districtId)
        childrenRef.value  = childrenRef.value.map(c =>
            memberIds.includes(c.id) ? { ...c, district_id: null, district_seats: null } : c
        )
        if (selectedDistrictId.value === districtId) selectedDistrictId.value = null
        deletingDistrictId.value = null
        restyleAll()
        showStatus('success', 'District disbanded')
    } catch (e) {
        console.error('deleteDistrict:', e)
        showStatus('error', 'Network error')
    }
}

// ── Mass tools ────────────────────────────────────────────────────────────────
function openMassTool(type) {
    massToolPanel.value = type
    massToolScope.value = null
}

function closeMassToolPanel() {
    massToolPanel.value = null
    massToolScope.value = null
}

function runMassTool() {
    const scope = massToolScope.value
    if (!scope || massToolRunning.value) return
    if (massToolPanel.value === 'reseed') runMassReseed(scope)
    else runMassDisband(scope)
}

async function runMassReseed(scope) {
    closeMassToolPanel()
    massToolRunning.value = true
    massJobRunning.value  = true
    startMassStatusPolling()
    try {
        const resp = await fetch(`/api/legislatures/${props.legislature.id}/mass-reseed`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body:    JSON.stringify({ operation_scope: scope, scope_id: props.scope.id, map_id: props.active_map?.id ?? null }),
        })
        const data = await resp.json()
        if (!resp.ok) { showStatus('error', data.error ?? 'Reseed failed'); return }
        showStatus('success', `Reseed: ${data.districts_created} districts created across ${data.scopes_processed} scope(s)`)
        // Stop polling before navigating — prevents the polling timer from firing a
        // second router.visit() while this one is already in flight (double-navigation bug)
        clearInterval(massStatusTimer)
        massStatusTimer = null
        massJobRunning.value = false
        router.visit(mapUrl(props.scope.id))
    } catch (e) {
        console.error('massReseed:', e)
        showStatus('error', 'Network error')
    } finally {
        massToolRunning.value = false
    }
}

async function runMassDisband(scope) {
    closeMassToolPanel()
    massToolRunning.value = true
    massJobRunning.value  = true
    startMassStatusPolling()
    try {
        const resp = await fetch(`/api/legislatures/${props.legislature.id}/mass-disband`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body:    JSON.stringify({ operation_scope: scope, scope_id: props.scope.id, map_id: props.active_map?.id ?? null }),
        })
        const data = await resp.json()
        if (!resp.ok) { showStatus('error', data.error ?? 'Clear failed'); return }
        showStatus('success', `Clear: ${data.districts_deleted} districts removed across ${data.scopes_processed} scope(s)`)
        // Stop polling before navigating — prevents double router.visit()
        clearInterval(massStatusTimer)
        massStatusTimer = null
        massJobRunning.value = false
        router.visit(mapUrl(props.scope.id))
    } catch (e) {
        console.error('massDisband:', e)
        showStatus('error', 'Network error')
    } finally {
        massToolRunning.value = false
    }
}

async function runRecolor() {
    if (massToolRunning.value || massJobRunning.value) return
    massJobRunning.value = true
    startMassStatusPolling()
    try {
        const resp = await fetch(`/api/legislatures/${props.legislature.id}/recolor`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body:    JSON.stringify({ map_id: props.active_map?.id ?? null }),
        })
        const data = await resp.json()
        if (!resp.ok) {
            clearInterval(massStatusTimer)
            massStatusTimer = null
            massJobRunning.value = false
            showStatus('error', data.error ?? 'Recolor failed')
            return
        }
        // Job queued — polling will detect completion and reload the page
    } catch (e) {
        console.error('recolor:', e)
        clearInterval(massStatusTimer)
        massStatusTimer = null
        massJobRunning.value = false
        showStatus('error', 'Network error')
    }
}

// ── XHR-based fetch helper with accurate gzip-aware progress ─────────────────
// fetch()+ReadableStream reports decompressed byte counts but Content-Length is
// the compressed size, so progress immediately overshoots 100%.  XHR's onprogress
// reports *network* bytes (compressed), matching Content-Length exactly.
// onBytes(received, total) — total is 0 when Content-Length is absent.
// timeout=0 means no client-side timeout (rely on server/nginx limit instead).
// Use 0 for slow cold-cache endpoints like revealed.geojson; 45s for fast ones.
function fetchJsonXhr(url, onBytes, timeout = 45000) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest()
        xhr.open('GET', url)
        xhr.responseType = 'text'
        xhr.onprogress = (e) => onBytes(e.loaded, e.lengthComputable ? e.total : 0)
        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try { resolve(JSON.parse(xhr.responseText)) }
                catch (e) { reject(e) }
            } else {
                reject(new Error(`HTTP ${xhr.status}`))
            }
        }
        xhr.onerror   = () => reject(new Error('Network error'))
        xhr.timeout   = timeout
        xhr.ontimeout = () => reject(new Error('Timeout'))
        xhr.send()
    })
}

// ── Map layer init (called on mount AND on every scope change via watch) ───────
async function reinitMapLayers() {
    if (!_map) return

    // Capture revision immediately — any later reinitMapLayers() call will increment this,
    // letting us detect that our in-flight fetches have been superseded and should be discarded.
    const myRevision = ++_reinitRevision

    // 1. Remove all Leaflet layers (no tile layer, so remove everything)
    _map.eachLayer(layer => _map.removeLayer(layer))

    // 2. Clear layer registry
    for (const k of Object.keys(layerByJid)) delete layerByJid[k]

    // 3. Reset reactive state for the new scope
    brokenGiantIds.value     = new Set()
    selectedDistrictId.value = null
    editingDistrictId.value  = null
    pendingAdd.value         = new Set()
    pendingRemove.value      = new Set()

    // 4. Clear label group references (toggle refs intentionally NOT reset — user's toggle state
    //    persists across scope changes; rebuildDistrictLabelGroup() returns early while null).
    districtLabelGroup = null
    _districtLabelData = []
    jurisdictionLabelGroup = null

    // 5. Sync childrenRef / districtsRef from the latest Inertia props
    childrenRef.value = props.children.map(c => ({ ...c }))
    districtsRef.value = props.districts.map(d => ({
        ...d,
        color_index: d.color_index ?? 0,
        members: d.members.map(m => ({ ...m })),
    }))

    mapLoading.value   = true
    mapLoadBytes.value = 0
    mapTotalBytes.value = 0

    // 6. Fresh per-scope lookups — O(1) maps used by tooltip closures and label loop
    const childById = {}
    for (const c of childrenRef.value) childById[c.id] = c
    const districtById = {}
    for (const d of districtsRef.value) districtById[d.id] = d

    // Single shared tooltip — prevents multiple tooltips appearing simultaneously
    // (the per-layer bindTooltip + bringToFront() combo causes race conditions)
    const sharedTooltip = L.tooltip({ opacity: 0.92, className: 'leaflet-legislature-tooltip' })

    try {
        // Determine the zoom level the map will settle at after fitBounds().
        // _map.getZoom() is called BEFORE fitBounds() and therefore returns either
        // undefined (first mount) or the previous scope's zoom — both wrong.
        // Instead, predict the post-fitBounds zoom from the scope's bounding box so
        // the simplification tolerance matches what the user actually sees.
        let z
        if (props.scope.bbox) {
            // Clamp to Web Mercator safe range — Leaflet's getBoundsZoom returns Infinity
            // for bounds that touch ±90° (poles are at infinity in EPSG:3857).
            const [south, west, north, east] = props.scope.bbox
            const safeSouth = Math.max(south, -85)
            const safeNorth = Math.min(north,  85)
            const scopeBounds = L.latLngBounds([[safeSouth, west], [safeNorth, east]])
            const predicted = _map.getBoundsZoom(scopeBounds, false)
            z = Number.isFinite(predicted) ? Math.round(predicted) : 6
        } else {
            z = Math.round(_map.getZoom()) || 6
        }

        // Fetch children GeoJSON and revealed sub-districts in parallel,
        // tracking download progress so the loading bar fills in real time.
        let childBytes = 0, childTotal = 0
        let revBytes   = 0, revTotal   = 0
        const updateProgress = () => {
            mapLoadBytes.value  = childBytes + revBytes
            mapTotalBytes.value = (childTotal > 0 && revTotal > 0)
                ? childTotal + revTotal
                : 0   // indeterminate until both Content-Length headers are known
        }

        const [gj, revGj] = await Promise.all([
            fetchJsonXhr(
                `/api/jurisdictions/${props.scope.id}/children.geojson?zoom=${z}`,
                (b, t) => { childBytes = b; childTotal = t; updateProgress() }
            ).catch(() => ({ features: [] })),
            fetchJsonXhr(
                `/api/legislatures/${props.legislature.id}/revealed.geojson?scope=${props.scope.id}&map=${props.active_map?.id ?? ''}&zoom=${z}`,
                (b, t) => { revBytes = b; revTotal = t; updateProgress() },
                0   // no client timeout — cold PostGIS query can take >45s; nginx allows 300s
            ).catch(() => ({ features: [] })),
        ])

        // Guard: if the user navigated to a different scope/map while our fetches were in flight,
        // a newer reinitMapLayers() has already started (and cleared the map). Adding our now-stale
        // layers would create phantom polygons (e.g. GBR 01 from Earth scope appearing on UK scope).
        // JS is single-threaded, so by the time an old Promise.all resolves the newer revision has
        // already been set — simply discard and return.
        if (myRevision !== _reinitRevision) {
            mapLoading.value = false
            return
        }

        // Split revealed features: parent_outline features become the non-interactive outline layer;
        // sub_district features become the coloured revealed layer.
        // Must run BEFORE childLayer is built since brokenGiantIds drives getLayerStyle().
        const revColoredFeats = (revGj.features ?? []).filter(f => f.properties?.type !== 'parent_outline')
        const revOutlineFeats  = (revGj.features ?? []).filter(f => f.properties?.type === 'parent_outline')

        if (revColoredFeats.length > 0) {
            brokenGiantIds.value = new Set(
                revColoredFeats.map(f => f.properties.giant_jurisdiction_id ?? f.properties.parent_jurisdiction_id)
            )
        }

        const childLayer = L.geoJSON(gj, {
            style: feat => {
                const jid = feat.id ?? feat.properties?.id
                return getLayerStyle(jid)
            },

            onEachFeature(feat, layer) {
                const jid   = feat.id ?? feat.properties?.id
                const child = childById[jid]
                if (!child) return

                layerByJid[jid] = layer

                // Dynamic tooltip content (called on each mouseover/mousemove)
                function tooltipContent() {
                    const c = childById[jid] ?? child
                    const isGiant = c.fractional_seats >= GIANT_THRESHOLD
                    const lines = [
                        `<strong>${c.name}</strong>`,
                        `Pop: ${c.population.toLocaleString()}`,
                        `Fractional: ${c.fractional_seats.toFixed(2)}`,
                    ]
                    if (isGiant) {
                        lines.push('<em style="color:#94a3b8">Expand in sidebar to see sub-districts</em>')
                    } else if (c.district_seats !== null) {
                        const dist = districtById[c.district_id]
                        lines.push(`District: ${c.district_seats} seats${dist ? ` · ${dist.members.length} members` : ''}`)
                    } else {
                        lines.push('<em style="color:#fbbf24">Unassigned — compositable</em>')
                    }
                    // Edit-mode hints
                    if (!isGiant && editingDistrictId.value && editingDistrictId.value !== 'new') {
                        const editDist = districtById[editingDistrictId.value]
                        const isMember = editDist?.members.some(m => m.id === jid)
                        if (isMember) lines.push('<span style="color:#4ade80">Click to remove</span>')
                        else if (!c.district_id) lines.push('<span style="color:#fbbf24">Click to add</span>')
                    } else if (!isGiant && editingDistrictId.value === 'new' && !c.district_id) {
                        lines.push('<span style="color:#fbbf24">Click to select</span>')
                    }
                    return lines.join('<br>')
                }

                // Tooltip: use shared instance so only one can ever be visible at a time
                layer.on('mouseover', function (e) {
                    sharedTooltip.setContent(tooltipContent())
                    sharedTooltip.setLatLng(e.latlng)
                    sharedTooltip.addTo(_map)
                    const style = getLayerStyle(jid)
                    layer.setStyle({
                        ...style,
                        fillOpacity: Math.min((style.fillOpacity ?? 0.65) + 0.15, 0.9),
                        weight: Math.max((style.weight ?? 1) + 1, 2),
                    })
                    layer.bringToFront()
                })
                layer.on('mousemove', function (e) {
                    sharedTooltip.setLatLng(e.latlng)
                    sharedTooltip.setContent(tooltipContent())
                })
                layer.on('mouseout', function () {
                    sharedTooltip.remove()
                    layer.setStyle(getLayerStyle(jid))
                })

                layer.on('click', function () {
                    const c = childrenRef.value.find(x => x.id === jid) ?? child
                    const isGiant = c.fractional_seats >= GIANT_THRESHOLD

                    // Edit existing district — click member to remove, click other to add/steal
                    if (editingDistrictId.value && editingDistrictId.value !== 'new') {
                        if (isGiant) return  // can't add giants to a district
                        const editDist = districtsRef.value.find(d => d.id === editingDistrictId.value)
                        const isMember = editDist?.members.some(m => m.id === jid)
                        if (isMember) togglePendingRemove(jid)
                        else          togglePendingAdd(jid)   // steal from other district or add unassigned
                        return
                    }

                    // New district mode — allow clicking unassigned OR stealable polygons
                    if (editingDistrictId.value === 'new') {
                        if (!isGiant) togglePendingAdd(jid)
                        return
                    }

                    // Browse mode
                    if (isGiant && c.child_count > 0) {
                        drillTo(jid)
                    } else if (c.district_id) {
                        toggleSelectDistrict(c.district_id, /* fromMap */ true)
                    } else {
                        // Click unassigned compositable polygon → auto-start new district
                        startNewDistrict()
                        togglePendingAdd(jid)
                    }
                })
            },
        }).addTo(_map)

        if (gj.features.length > 0) {
            _map.fitBounds(childLayer.getBounds(), { padding: [30, 30] })
        }

        // ── District label layers (combined badge per district, toggled via buttons) ──
        districtLabelGroup     = L.layerGroup()
        jurisdictionLabelGroup = L.layerGroup()

        // Pre-build district → member layers map (O(C)).
        // Moved before the Jurs loop so districtCenterMap is available for collision detection.
        // Center priority:
        //  1. PostGIS ST_PointOnSurface centroid (dist.centroid) — guaranteed inside polygon.
        //  2. Validation: reject centroid if outside every member polygon bbox (antimeridian guard).
        //  3. Fall back to bbox center of the largest individual member polygon.
        const districtLayerMap = new Map()  // district_id → [{ layer, bounds }]
        childLayer.eachLayer(layer => {
            const jid = layer.feature?.id ?? layer.feature?.properties?.id
            const c = childById[jid]
            if (!c || !c.district_id) return
            let bounds = null
            try { bounds = layer.getBounds() } catch (_) {}
            if (!districtLayerMap.has(c.district_id)) districtLayerMap.set(c.district_id, [])
            districtLayerMap.get(c.district_id).push({ layer, bounds })
        })

        // Pre-compute validated center for every district (O(D)).
        // Used for: (a) Jurs label collision detection, (b) building _districtLabelData.
        const districtCenterMap = new Map()  // district_id → LatLng
        for (const dist of districtsRef.value) {
            const memberLayers = districtLayerMap.get(dist.id) ?? []
            let center = undefined
            if (dist.centroid) {
                const proposed = L.latLng(dist.centroid.lat, dist.centroid.lng)
                let valid = false
                for (const { bounds: b } of memberLayers) {
                    if (valid || !b) continue
                    try {
                        const sw = b.getSouthWest(), ne = b.getNorthEast()
                        if (ne.lng - sw.lng > 180) continue
                        if (b.contains(proposed)) valid = true
                    } catch (_) {}
                }
                if (valid) center = proposed
            }
            if (!center) {
                let best = null, bestArea = -1
                for (const { bounds: b } of memberLayers) {
                    if (!b) continue
                    try {
                        const sw = b.getSouthWest(), ne = b.getNorthEast()
                        const lngSpan = ne.lng - sw.lng
                        if (lngSpan > 180) continue
                        const area = (ne.lat - sw.lat) * lngSpan
                        if (area > bestArea) { bestArea = area; best = b }
                    } catch (_) {}
                }
                if (best) center = best.getCenter()
            }
            if (center) districtCenterMap.set(dist.id, center)
        }

        // Jurisdiction name labels: one per child polygon.
        // If the polygon center is within 0.2° of a district label badge, offset the
        // jurisdiction label upward by 30% of the polygon's latitude span rather than
        // suppressing it — both labels then appear without stacking on the same point.
        const JURS_COLLIDE = 0.2
        for (const child of childrenRef.value) {
            const layer = layerByJid[child.id]
            if (!layer) continue
            let center = null
            try {
                const b  = layer.getBounds()
                const sw = b.getSouthWest(), ne = b.getNorthEast()
                if (ne.lng - sw.lng <= 180) center = b.getCenter()
            } catch (_) {}
            if (!center) continue
            let collides = false
            for (const dc of districtCenterMap.values()) {
                if (Math.abs(dc.lat - center.lat) < JURS_COLLIDE &&
                    Math.abs(dc.lng - center.lng) < JURS_COLLIDE) { collides = true; break }
            }
            if (collides) {
                // Offset upward just enough so the jurisdiction label clears the district badge
                // without moving too far from the polygon center.
                try {
                    const b      = layer.getBounds()
                    const latOff = (b.getNorth() - b.getSouth()) * 0.20
                    center       = L.latLng(center.lat + latOff, center.lng)
                } catch (_) {}
            }
            jurisdictionLabelGroup.addLayer(L.marker(center, {
                icon: L.divIcon({
                    className: '',
                    html: `<div class="jurisdiction-name-label">${child.name}<br><span class="jurisdiction-pop-label">${formatPop(child.population)}</span></div>`,
                    iconSize:   null,
                    iconAnchor: [0, 0],
                }),
                interactive: false,
            }))
        }

        // Build _districtLabelData for regular districts.
        // rebuildDistrictLabelGroup() renders combined badges from this cache on each toggle.
        _districtLabelData = []
        for (const dist of districtsRef.value) {
            const center = districtCenterMap.get(dist.id)
            if (!center) continue
            const color     = districtFillColor(dist.color_index)
            const totalPop  = dist.members.reduce((s, m) => s + m.population, 0)
            const totalFrac = dist.members.reduce((s, m) => s + m.fractional_seats, 0)
            const dev = dist.seats > 0 ? (totalFrac / dist.seats - 1) * 100 : null
            _districtLabelData.push({
                distId: dist.id, center, name: dist.name, seats: dist.seats,
                popStr: formatPop(totalPop), fracStr: totalFrac.toFixed(2), color,
                chr:          dist.convex_hull_ratio != null ? dist.convex_hull_ratio : null,
                isContiguous: dist.is_contiguous    != null ? dist.is_contiguous    : null,
                dev,
            })
        }

        // Revealed sub-district layer: individual jurisdiction polygons for broken-down giants.
        // Each jurisdiction is one GeoJSON feature coloured by its district's color_index.
        // Stroke colour matches fill colour → same-district shared borders are invisible;
        // cross-district borders are visible via colour contrast at the same weight (1) as
        // surrounding country polygons — no artificial boldness.
        if (revColoredFeats.length > 0) {
            // district_id → [{layer, feat}] — populated during onEachFeature so that
            // mouseover can highlight ALL jurisdictions in the same district at once.
            const districtLayerMap = new Map()

            const revealedLayer = L.geoJSON({ type: 'FeatureCollection', features: revColoredFeats }, {
                style: feat => {
                    const color = DISTRICT_COLORS[feat.properties.color_index ?? 0]
                    return { fillColor: color, fillOpacity: 0.65, color, weight: 1, opacity: 1 }
                },
                onEachFeature(feat, layer) {
                    // Register this layer under its district so siblings can be found later
                    const distId = feat.properties.district_id
                    if (!districtLayerMap.has(distId)) districtLayerMap.set(distId, [])
                    districtLayerMap.get(distId).push({ layer, feat })

                    layer.on('mouseover', e => {
                        sharedTooltip.setContent(
                            `<strong>${feat.properties.parent_name}</strong><br>` +
                            `Sub-district · ${feat.properties.seats} seats`
                        )
                        sharedTooltip.setLatLng(e.latlng).addTo(_map)
                        // Highlight every jurisdiction belonging to this district together
                        const siblings = districtLayerMap.get(distId) ?? []
                        siblings.forEach(({ layer: l, feat: f }) => {
                            const c = DISTRICT_COLORS[f.properties.color_index ?? 0]
                            l.setStyle({ fillColor: c, fillOpacity: 0.85, color: c, weight: 2, opacity: 1 })
                        })
                    })
                    layer.on('mousemove', e => sharedTooltip.setLatLng(e.latlng))
                    layer.on('mouseout', () => {
                        sharedTooltip.remove()
                        // Restore every jurisdiction in this district back to default
                        const siblings = districtLayerMap.get(distId) ?? []
                        siblings.forEach(({ layer: l, feat: f }) => {
                            const c = DISTRICT_COLORS[f.properties.color_index ?? 0]
                            l.setStyle({ fillColor: c, fillOpacity: 0.65, color: c, weight: 1, opacity: 1 })
                        })
                    })
                    // Click drills into the parent giant jurisdiction (skip in edit/new-district mode)
                    layer.on('click', () => {
                        if (editingDistrictId.value) return  // don't interrupt edit / new-district mode
                        drillTo(feat.properties.parent_jurisdiction_id)
                    })
                },
            }).addTo(_map)

            // Build per-district label data from revealed sub-layers:
            // group bounds, count members, keep one representative feature per district.
            // Also track largestBounds (biggest individual polygon by lat/lng area) so that
            // the label center uses the largest sub-polygon's centroid rather than the combined
            // bounding box center — which can land in the wrong hemisphere when a district
            // spans the antimeridian (e.g. USA districts including Alaska + Pacific territories).
            const revDistMap = new Map()  // district_id → { bounds, feat, memberCount, largestBounds, largestArea }
            revealedLayer.eachLayer(subLayer => {
                const feat = subLayer.feature
                if (!feat) return
                const distId = feat.properties.district_id
                try {
                    const b  = subLayer.getBounds()
                    const sw = b.getSouthWest(), ne = b.getNorthEast()
                    // Polygons whose bounding box spans > 180° of longitude cross the antimeridian
                    // (e.g. Alaska with Aleutians at +173°E mixed with –130°W vertices, or Russia's
                    // Chukotka with vertices on both sides of ±180°). Their getCenter() lands near
                    // 0°E (North Sea). Only track non-crossing polygons for the label position.
                    const lngSpan    = ne.lng - sw.lng   // raw, always ≥ 0 from Leaflet
                    const notCrossing = lngSpan <= 180
                    const area       = notCrossing ? (ne.lat - sw.lat) * lngSpan : -1
                    if (!revDistMap.has(distId)) {
                        revDistMap.set(distId, {
                            bounds:        L.latLngBounds(b.getSouthWest(), b.getNorthEast()),
                            feat,
                            memberCount:   1,
                            largestBounds: notCrossing ? b : null,
                            largestArea:   area,
                        })
                    } else {
                        const e = revDistMap.get(distId)
                        e.bounds.extend(b)
                        e.memberCount++
                        if (notCrossing && area > e.largestArea) {
                            e.largestBounds = b
                            e.largestArea   = area
                        }
                    }
                } catch (_) {}
            })

            // One seats, pop/frac, and name label per revealed district.
            // Use largestBounds (largest non-antimeridian-crossing polygon) for the center;
            // fall back to combined bounds.getCenter() only if every polygon crossed the antimeridian.
            for (const [distId, { bounds, feat, memberCount, largestBounds }] of revDistMap) {
                const center    = (largestBounds ?? bounds).getCenter()
                const seats     = feat.properties.seats
                const color     = DISTRICT_COLORS[feat.properties.color_index ?? 0]
                const distPop    = feat.properties.district_population
                const distFrac   = feat.properties.district_fractional_seats
                // Fallback: derive population from fractional_seats × quota when actual_population = 0
                const displayPop = distPop > 0 ? distPop : (distFrac > 0 ? Math.round(distFrac * props.quota) : 0)

                // Push to _districtLabelData — rebuildDistrictLabelGroup() renders the combined badge
                const distName = feat.properties.district_number != null
                    ? revealedDistrictName(feat, memberCount)
                    : null
                _districtLabelData.push({
                    distId, center,
                    name:    distName,
                    seats,
                    popStr:  displayPop > 0 ? formatPop(displayPop) : '—',
                    fracStr: distFrac > 0 ? Number(distFrac).toFixed(2) : '—',
                    color,
                    chr:          feat.properties.convex_hull_ratio ?? null,
                    isContiguous: feat.properties.is_contiguous ?? null,
                    dev:          (seats > 0 && distFrac > 0) ? (distFrac / seats - 1) * 100 : null,
                })
            }
        }

        // Parent outline layer — non-interactive black stroke drawn on top of the revealed
        // sub-district fill. Preserves the outer border of every broken-down giant jurisdiction
        // at any depth (depth-1 country outlines at Earth scope, depth-2 province outlines, etc.).
        if (revOutlineFeats.length > 0) {
            L.geoJSON({ type: 'FeatureCollection', features: revOutlineFeats }, {
                style: feat => {
                    const depth = feat.properties.depth ?? 1
                    if (depth >= 3) {
                        // Great-grandchildren: fine dots, barely-there guide line
                        return { fill: false, color: '#0f172a', weight: 0.5, opacity: 0.55, dashArray: '2 5' }
                    }
                    if (depth === 2) {
                        // Grandchildren (e.g. California within USA at Earth scope): dashed, thinner
                        return { fill: false, color: '#0f172a', weight: 0.75, opacity: 0.7, dashArray: '5 5' }
                    }
                    // depth 1: direct children of scope — solid weight 1, matches STYLE_NORMAL
                    return { fill: false, color: '#0f172a', weight: 1, opacity: 1 }
                },
                interactive: false,
            }).addTo(_map)
        }

        // Apply the user's current toggle state to the freshly-built label groups.
        rebuildDistrictLabelGroup()
        if (showJurisdictionLabels.value && jurisdictionLabelGroup) jurisdictionLabelGroup.addTo(_map)

    } catch (e) {
        console.error('Failed to load GeoJSON:', e)
    } finally {
        mapLoading.value = false
    }
}

// ── Map init ──────────────────────────────────────────────────────────────────
onMounted(async () => {
    // If a mass job was already running when the page loaded, start polling immediately
    if (massJobRunning.value) startMassStatusPolling()

    // No tile layer — runs fully offline. Background colour set via CSS (#000000 ocean/space).
    _map = L.map('legislature-map', { zoomControl: true })

    // Clicking ocean/background in new-district mode with nothing selected = implicit cancel
    _map.on('click', function () {
        if (editingDistrictId.value === 'new' && pendingAdd.value.size === 0) {
            cancelEdit()
        }
    })

    // ── Drag / rubber-band select / deselect ─────────────────────────────────
    // Activated when isDragSelectMode is true (only available in edit mode).
    //
    // Modifiers at mousedown determine the operation for the whole drag gesture:
    //   No modifier  → ADD: queue unassigned jurisdictions into pendingAdd
    //   Shift held   → ADD (all): like above but also include already-assigned ones
    //   Ctrl held    → REMOVE: dequeue from pendingAdd (for unconfirmed staged adds),
    //                  or queue into pendingRemove (for confirmed district members)
    let _dragStart    = null   // container point where the drag began
    let _dragIsRemove = false  // true = Ctrl was held at mousedown → remove gesture

    _map.on('mousedown', function (e) {
        if (!isDragSelectMode.value) return
        e.originalEvent.preventDefault()
        _map.dragging.disable()
        _dragStart    = _map.latLngToContainerPoint(e.latlng)
        _dragIsRemove = e.originalEvent.ctrlKey || e.originalEvent.metaKey
        if (rubberBandEl.value) {
            const rb = rubberBandEl.value
            // Red border for remove, blue for add
            rb.style.borderColor = _dragIsRemove ? '#f87171' : '#60a5fa'
            rb.style.background  = _dragIsRemove ? 'rgba(248,113,113,0.08)' : 'rgba(96,165,250,0.08)'
            rb.style.left    = _dragStart.x + 'px'
            rb.style.top     = _dragStart.y + 'px'
            rb.style.width   = '0'
            rb.style.height  = '0'
            rb.style.display = 'block'
        }
    })

    _map.on('mousemove', function (e) {
        if (!isDragSelectMode.value || !_dragStart) return
        const cur = _map.latLngToContainerPoint(e.latlng)
        if (rubberBandEl.value) {
            const rb = rubberBandEl.value
            rb.style.left   = Math.min(_dragStart.x, cur.x) + 'px'
            rb.style.top    = Math.min(_dragStart.y, cur.y) + 'px'
            rb.style.width  = Math.abs(cur.x - _dragStart.x) + 'px'
            rb.style.height = Math.abs(cur.y - _dragStart.y) + 'px'
        }
    })

    _map.on('mouseup', function (e) {
        if (!isDragSelectMode.value || !_dragStart) return
        _map.dragging.enable()
        if (rubberBandEl.value) rubberBandEl.value.style.display = 'none'

        const end        = _map.latLngToContainerPoint(e.latlng)
        const swPx       = L.point(Math.min(_dragStart.x, end.x), Math.max(_dragStart.y, end.y))
        const nePx       = L.point(Math.max(_dragStart.x, end.x), Math.min(_dragStart.y, end.y))
        const sw         = _map.containerPointToLatLng(swPx)
        const ne         = _map.containerPointToLatLng(nePx)
        const selBounds  = L.latLngBounds(sw, ne)
        const shiftHeld  = e.originalEvent.shiftKey
        const isRemove   = _dragIsRemove
        _dragStart       = null
        _dragIsRemove    = false

        const editDist = editingDistrictId.value !== 'new'
            ? districtsRef.value.find(d => d.id === editingDistrictId.value)
            : null

        for (const child of childrenRef.value) {
            const layer = layerByJid[child.id]
            if (!layer) continue
            let center = null
            try { center = layer.getBounds().getCenter() } catch (_) {}
            if (!center || !selBounds.contains(center)) continue

            if (isRemove) {
                // Remove mode: un-stage pending adds, or stage current members for removal
                if (pendingAdd.value.has(child.id)) {
                    togglePendingAdd(child.id)  // un-stage
                } else if (editDist?.members.some(m => m.id === child.id)) {
                    if (!pendingRemove.value.has(child.id)) togglePendingRemove(child.id)
                }
            } else {
                // Add mode: skip already-assigned unless Shift held
                const isAssigned = !!child.district_id
                if (isAssigned && !shiftHeld) continue
                if (!pendingAdd.value.has(child.id)) togglePendingAdd(child.id)
            }
        }
        restyleAll()
    })

    await reinitMapLayers()
})

// Re-initialize map layers when the user drills to a different scope.
// Inertia does a partial prop update (not a full remount), so onMounted never
// re-runs — we need this watch to reload the correct children + districts.
watch(() => props.scope.id, async () => {
    await reinitMapLayers()
})

// Re-initialize map layers when the active map changes (e.g. switching from Test Map to another).
// Without this, the old map's layers stay visible even though a different map is selected.
watch(() => props.active_map?.id, async () => {
    await reinitMapLayers()
})

// Highlight name labels when a district is selected/deselected.
// Uses data-district-id on the .district-label-name divs to toggle invert style.
watch(selectedDistrictId, (newId, oldId) => {
    if (oldId) {
        document.querySelectorAll(`.district-label-name[data-district-id="${oldId}"]`).forEach(el => {
            el.style.background = ''
            el.style.color      = ''
        })
    }
    if (newId) {
        const d     = districtsRef.value.find(x => x.id === newId)
        const color = districtFillColor(d?.color_index ?? 0)
        document.querySelectorAll(`.district-label-name[data-district-id="${newId}"]`).forEach(el => {
            el.style.background = color
            el.style.color      = '#0f172a'
        })
    }
})
</script>

<style>
/* Ocean background — no tile layer needed */
#legislature-map .leaflet-container {
    background: #000000 !important;
}


/* Shared tooltip styling */
.leaflet-legislature-tooltip {
    background: rgba(15, 23, 42, 0.95);
    border: 1px solid #334155;
    color: #e2e8f0;
    font-size: 12px;
    line-height: 1.5;
    padding: 6px 9px;
    border-radius: 4px;
    pointer-events: none;
    white-space: nowrap;
}
.leaflet-legislature-tooltip::before {
    display: none;
}

/* Fade transition for status toast */
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease; }
.fade-enter-from, .fade-leave-to       { opacity: 0; }

/* bg-gray-850 utility (not in default Tailwind) */
.bg-gray-850 { background-color: #1a2332; }

/* District name/info combined badge — centered on PointOnSurface centroid.
   Content grows with each active toggle: name, then seats, then pop/frac. */
.district-label-name {
    transform: translate(-50%, -50%);    /* center the div on the anchor point */
    background: rgba(15, 23, 42, 0.88);
    color: #e2e8f0;
    border: 2px solid;                   /* color set via inline style */
    border-radius: 4px;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 700;
    font-family: ui-monospace, monospace;
    white-space: nowrap;
    pointer-events: none;
    box-shadow: 0 1px 5px rgba(0,0,0,0.55);
    letter-spacing: 0.04em;
    transition: background 0.15s, color 0.15s;
}

/* Sub-line stat text inside the combined district badge (seats / pop / frac) */
.district-label-stat {
    display: block;
    font-size: 11px;
    font-weight: 400;
    color: #f1f5f9;
    letter-spacing: 0.02em;
}

/* Jurisdiction name labels — subdued, centered on polygon bounding-box center */
.jurisdiction-name-label {
    transform: translate(-50%, -50%);
    background: rgba(0, 0, 0, 0.60);
    color: #e2e8f0;
    border-radius: 3px;
    padding: 2px 5px;
    font-size: 11px;
    font-weight: 500;
    white-space: nowrap;
    pointer-events: none;
    text-align: center;
    line-height: 1.4;
    text-shadow: 0 1px 3px rgba(0,0,0,0.95);
}
.jurisdiction-pop-label {
    color: #cbd5e1;
    font-size: 10px;
    font-weight: 400;
}

/* Rubber-band drag-select/remove overlay.
   Border color and background are set dynamically in JS:
   blue (#60a5fa) for add, red (#f87171) for remove (Ctrl held). */
.rubber-band {
    position: absolute;
    border: 2px dashed #60a5fa;
    background: rgba(96, 165, 250, 0.08);
    pointer-events: none;
    z-index: 1000;
}
</style>
