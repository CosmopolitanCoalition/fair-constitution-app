<template>
    <AppLayout>
        <div class="flex flex-1 overflow-hidden" style="height: calc(100vh - 49px);">

            <!-- Left panel: metadata -->
            <aside class="w-80 shrink-0 bg-gray-900 border-r border-gray-800 flex flex-col overflow-y-auto">

                <!-- Breadcrumb -->
                <div class="px-4 py-3 border-b border-gray-800 text-xs text-gray-400 flex flex-wrap gap-1 items-center">
                    <template v-if="jurisdiction.ancestors.length > 0">
                        <a href="/jurisdictions" class="hover:text-white transition-colors">
                            {{ jurisdiction.ancestors[0].name }}
                        </a>
                        <template v-for="ancestor in jurisdiction.ancestors.slice(1)" :key="ancestor.id">
                            <span class="text-gray-600">›</span>
                            <a :href="`/jurisdictions/${ancestor.id}`" class="hover:text-white transition-colors">
                                {{ ancestor.name }}
                            </a>
                        </template>
                    </template>
                    <template v-else>
                        <a href="/jurisdictions" class="hover:text-white transition-colors">World</a>
                    </template>
                    <span class="text-gray-600">›</span>
                    <span class="text-gray-200">{{ jurisdiction.name }}</span>
                </div>

                <!-- Main info -->
                <div class="p-4 flex flex-col gap-4">
                    <div>
                        <span class="inline-block text-xs font-medium px-2 py-0.5 rounded-full bg-blue-900 text-blue-300 mb-2">
                            {{ jurisdiction.adm_label }}
                        </span>
                        <h1 class="text-xl font-bold text-white leading-tight">{{ jurisdiction.name }}</h1>
                        <p v-if="jurisdiction.iso_code" class="text-sm text-gray-400 mt-0.5">{{ jurisdiction.iso_code }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-gray-800 rounded-lg p-3">
                            <div class="text-xs text-gray-400 mb-1">Population</div>
                            <div class="text-base font-semibold text-white">
                                {{ jurisdiction.population.toLocaleString() }}
                            </div>
                            <div v-if="jurisdiction.population_year" class="text-xs text-gray-500 mt-0.5">
                                est. {{ jurisdiction.population_year }}
                            </div>
                        </div>
                        <div class="bg-gray-800 rounded-lg p-3">
                            <div class="text-xs text-gray-400 mb-1">Sub-jurisdictions</div>
                            <div class="text-base font-semibold text-white">
                                {{ childCount.toLocaleString() }}
                            </div>
                        </div>
                    </div>

                    <div v-if="jurisdiction.official_languages && jurisdiction.official_languages.length" class="bg-gray-800 rounded-lg p-3">
                        <div class="text-xs text-gray-400 mb-2">Official Languages</div>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="lang in jurisdiction.official_languages"
                                :key="lang"
                                class="inline-block text-xs font-mono font-medium px-2 py-0.5 rounded bg-gray-700 text-gray-200 uppercase"
                            >{{ lang }}</span>
                        </div>
                    </div>

                    <div class="bg-gray-800 rounded-lg p-3">
                        <div class="text-xs text-gray-400 mb-1">Timezone</div>
                        <div class="text-sm text-white">{{ jurisdiction.timezone }}</div>
                    </div>

                    <div class="bg-gray-800 rounded-lg p-3">
                        <div class="text-xs text-gray-400 mb-1">Data source</div>
                        <div class="text-sm text-white capitalize">{{ jurisdiction.source.replace(/_/g, ' ') }}</div>
                    </div>

                    <!-- Slug -->
                    <div class="bg-gray-800 rounded-lg p-3">
                        <div class="text-xs text-gray-400 mb-1">Slug</div>
                        <div class="text-xs font-mono text-gray-300 break-all">{{ jurisdiction.slug }}</div>
                    </div>

                    <!-- This jurisdiction's seats in its parent's legislature -->
                    <div v-if="type_a_apportioned != null" class="bg-gray-800 rounded-lg p-3">
                        <div class="text-xs text-gray-400 mb-2">Seats in Parent Legislature</div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <div class="text-xs text-gray-500">Population (A)</div>
                                <div class="text-sm font-semibold text-green-400">{{ type_a_apportioned }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Equal (B)</div>
                                <div class="text-sm font-semibold text-blue-400">{{ type_b_apportioned }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- This jurisdiction's own legislature composition (sum of children) -->
                    <div v-if="children_type_a_total != null" class="bg-gray-800 rounded-lg p-3">
                        <div class="text-xs text-gray-400 mb-2">This Legislature's Composition</div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <div class="text-xs text-gray-500">Population (A)</div>
                                <div class="text-sm font-semibold text-green-400">{{ children_type_a_total.toLocaleString() }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Equal (B)</div>
                                <div class="text-sm font-semibold text-blue-400">{{ children_type_b_total?.toLocaleString() ?? '0' }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Legislature & Districts link -->
                    <a v-if="legislature_id"
                       :href="`/legislatures/${legislature_id}`"
                       class="block w-full text-center text-xs font-medium px-3 py-2 rounded
                              bg-emerald-800 hover:bg-emerald-700 text-emerald-100 transition-colors">
                        View Legislature &amp; Districts →
                    </a>
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
                        {{ hoveredFeature.child_count }} sub-jurisdictions
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
                        {{ hoveredChild.child_count }} sub-jurisdictions
                    </div>
                    <div v-else class="text-xs text-gray-500 italic">No further sub-divisions</div>
                    <div v-if="hoveredChild.type_a_apportioned != null" class="mt-2 pt-2 border-t border-green-800 grid grid-cols-2 gap-1">
                        <div>
                            <div class="text-xs text-gray-500">A seats</div>
                            <div class="text-xs font-semibold text-green-400">{{ hoveredChild.type_a_apportioned }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">B seats</div>
                            <div class="text-xs font-semibold text-blue-400">{{ hoveredChild.type_b_apportioned }}</div>
                        </div>
                    </div>
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

                <!-- Label toggle panel -->
                <div class="absolute bottom-6 left-3 z-[1000] flex gap-1.5">
                    <button
                        v-for="opt in labelToggleOpts"
                        :key="opt.key"
                        @click="toggleLabel(opt.key)"
                        :title="opt.title"
                        class="px-2 py-1 rounded text-xs font-medium border transition-colors select-none"
                        :class="labelOpts[opt.key]
                            ? 'bg-blue-600 border-blue-500 text-white'
                            : 'bg-gray-900/80 border-gray-700 text-gray-400 hover:text-white hover:border-gray-500'"
                    >{{ opt.label }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({
    jurisdiction: Object,
    ancestors: Array,
    childCount: Number,
    hasChildren: Boolean,
    type_a_apportioned: Number,
    type_b_apportioned: Number,
    children_type_a_total: Number,
    children_type_b_total: Number,
    legislature_id: String,
})

props.jurisdiction.ancestors = props.ancestors

const loading      = ref(true)
const hoveredFeature = ref(null)
const hoveredChild   = ref(null)

// ── Label toggle state ────────────────────────────────────────────────────────
const labelOpts = reactive({
    population: false,
    subCount:   false,
    typeA:      false,
    typeB:      false,
})

const labelToggleOpts = [
    { key: 'population', label: 'Pop',  title: 'Show population on labels' },
    { key: 'subCount',   label: 'Sub',  title: 'Show sub-jurisdiction count on labels' },
    { key: 'typeA',      label: 'A',    title: 'Show population-house seats on labels' },
    { key: 'typeB',      label: 'B',    title: 'Show equal-house seats on labels' },
]

function toggleLabel(key) {
    labelOpts[key] = !labelOpts[key]
}

// ── Map styles ────────────────────────────────────────────────────────────────
const contextStyles = [
    { fillColor: '#64748b', fillOpacity: 0.28, color: '#475569', weight: 1 },
    { fillColor: '#94a3b8', fillOpacity: 0.16, color: '#64748b', weight: 0.5 },
    { fillColor: '#cbd5e1', fillOpacity: 0.10, color: '#94a3b8', weight: 0.5 },
]
const contextHoverStyles = [
    { fillColor: '#94a3b8', fillOpacity: 0.45, color: '#334155', weight: 1.5 },
    { fillColor: '#cbd5e1', fillOpacity: 0.35, color: '#475569', weight: 1 },
    { fillColor: '#e2e8f0', fillOpacity: 0.25, color: '#64748b', weight: 0.8 },
]
const contextLabelClasses = ['jl-d0', 'jl-d1', 'jl-d2']

const childStyle       = { fillColor: '#4a7c59', fillOpacity: 0.5,  color: '#2d4a35', weight: 1 }
const childHoverStyle  = { fillColor: '#6aad80', fillOpacity: 0.75, color: '#1a2e1f', weight: 2 }
const selfOutlineStyle = { fillOpacity: 0, color: '#94a3b8', weight: 2, dashArray: '5,5' }
const leafOutlineStyle = { fillColor: '#4a7c59', fillOpacity: 0.4,  color: '#2d4a35', weight: 2 }

onMounted(async () => {
    const map = L.map('jurisdiction-map', { zoomControl: true })

    // ── Label registry & redraw system ──────────────────────────────────────
    // Each entry: { lat, lng, p (feature properties), cssClass }
    const labelRegistry  = []
    let   activeTooltips = []

    function buildLabelHtml(p, cssClass) {
        const name = p.name
        let lines  = [`<strong>${name}</strong>`]
        if (labelOpts.population && p.population != null)
            lines.push(Number(p.population).toLocaleString())
        if (labelOpts.subCount && p.child_count != null)
            lines.push(`${p.child_count} sub`)
        if (labelOpts.typeA && p.type_a_apportioned != null)
            lines.push(`A:${p.type_a_apportioned}`)
        if (labelOpts.typeB && p.type_b_apportioned != null)
            lines.push(`B:${p.type_b_apportioned}`)
        return `<span class="${cssClass}">${lines.join('<br>')}</span>`
    }

    function redrawLabels() {
        activeTooltips.forEach(t => map.removeLayer(t))
        activeTooltips = []
        labelRegistry.forEach(({ lat, lng, p, cssClass }) => {
            const t = L.tooltip({
                permanent:   true,
                direction:   'center',
                className:   'jl-bare',
                interactive: false,
                opacity:     1,
            })
            .setLatLng([lat, lng])
            .setContent(buildLabelHtml(p, cssClass))
            .addTo(map)
            activeTooltips.push(t)
        })
    }

    // Re-render labels whenever any toggle changes
    watch(labelOpts, redrawLabels, { deep: true })

    function registerLabel(lat, lng, p, cssClass) {
        if (lat == null || lng == null) return
        labelRegistry.push({ lat, lng, p, cssClass })
        // Draw immediately with current opts
        const t = L.tooltip({
            permanent:   true,
            direction:   'center',
            className:   'jl-bare',
            interactive: false,
            opacity:     1,
        })
        .setLatLng([lat, lng])
        .setContent(buildLabelHtml(p, cssClass))
        .addTo(map)
        activeTooltips.push(t)
    }

    // ── Layer helpers ────────────────────────────────────────────────────────
    function addContextLayer(geojson, depth) {
        if (!geojson || geojson.features.length === 0) return

        const styleIdx   = Math.min(depth, contextStyles.length - 1)
        const style      = contextStyles[styleIdx]
        const hoverStyle = contextHoverStyles[styleIdx]
        const labelClass = contextLabelClasses[styleIdx]

        const layer = L.geoJSON(geojson, {
            style,
            onEachFeature(feature, featureLayer) {
                const p = feature.properties
                featureLayer.on('mouseover', () => {
                    featureLayer.setStyle(hoverStyle)
                    featureLayer.bringToFront()
                    hoveredFeature.value = { ...p, depth }
                })
                featureLayer.on('mouseout', () => {
                    layer.resetStyle(featureLayer)
                    if (hoveredFeature.value?.name === p.name) hoveredFeature.value = null
                })
                featureLayer.on('click', () => router.visit(`/jurisdictions/${p.id}`))
            },
        }).addTo(map)

        geojson.features.forEach(feature => {
            const p = feature.properties
            registerLabel(p.centroid_lat, p.centroid_lng, p, labelClass)
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

            const childLayer = L.geoJSON(childGeojson, {
                style: childStyle,
                onEachFeature(feature, featureLayer) {
                    const p = feature.properties
                    featureLayer.on('mouseover', () => {
                        featureLayer.setStyle(childHoverStyle)
                        featureLayer.bringToFront()
                        hoveredChild.value = p
                    })
                    featureLayer.on('mouseout', () => {
                        childLayer.resetStyle(featureLayer)
                        hoveredChild.value = null
                    })
                    featureLayer.on('click', () => router.visit(`/jurisdictions/${p.id}`))
                },
            }).addTo(map)

            childGeojson.features.forEach(feature => {
                const p = feature.properties
                registerLabel(p.centroid_lat, p.centroid_lng, p, 'jl-child')
            })

            const fitTarget = selfGeojson.features.length > 0
                ? L.geoJSON(selfGeojson).getBounds()
                : childLayer.getBounds()
            map.fitBounds(fitTarget, { padding: [40, 40] })

        } else {
            // Leaf: show context + self outline + self label
            ancestorsToLoad.forEach((ancestor, i) => {
                addContextLayer(ancestorGeojsons[i], ancestorsToLoad.length - i)
            })
            addContextLayer(siblingGeojson, 0)

            const selfLayer = L.geoJSON(selfGeojson, { style: leafOutlineStyle }).addTo(map)

            // Self label — works now that selfGeoJson includes centroid_lat/lng
            selfGeojson.features.forEach(feature => {
                const p = feature.properties
                registerLabel(p.centroid_lat, p.centroid_lng, p, 'jl-child')
            })

            if (selfGeojson.features.length > 0) {
                map.fitBounds(selfLayer.getBounds(), { padding: [40, 40] })
            }
        }

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
