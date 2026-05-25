<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({
    jurisdictionId: { type: String, default: null },
    // P.1.1: when false, skip the /raster.png fetch entirely and only show
    // the polygon outline. Set false during the geoboundaries phase — the
    // ETL hasn't loaded fresh raster tiles yet, so any raster the endpoint
    // returns is leftover from a previous run and visually misleading.
    showRaster:    { type: Boolean, default: true },
    // Minimum milliseconds a successfully-loaded jurisdiction stays visible
    // before the MiniMap will accept a new one. The ETL fires heartbeats far
    // faster than a polygon + raster can render (hundreds per second for
    // county-level passes), so we lock in whatever we have until the user has
    // had a moment to see it. Fresh IDs arriving during the lock are queued
    // and the newest-at-unlock wins.
    minDisplayMs: { type: Number, default: 10000 },
})

const mapContainer = ref(null)
const status = ref('idle')   // idle | loading | polygon | overlay | empty | error
const errorMsg = ref('')

let map          = null
let polygonLayer = null
let rasterLayer  = null
let rasterBlobUrl = null
let activeLoadId = 0  // guards against race conditions on quick jurisdiction changes
let currentlyLoadedId = null  // id of the jurisdiction whose layers are on the map
let lastLoadAt    = 0    // timestamp of last successful terminal render
let pendingId     = null // newest id seen while locked; loaded on unlock
let unlockTimer   = null
let isLoading     = false // true while a polygon+raster fetch sequence is in flight

function destroyLayers() {
    if (rasterLayer) { map && map.removeLayer(rasterLayer); rasterLayer = null }
    if (polygonLayer) { map && map.removeLayer(polygonLayer); polygonLayer = null }
    if (rasterBlobUrl) { URL.revokeObjectURL(rasterBlobUrl); rasterBlobUrl = null }
}

function scheduleUnlock(remainingMs) {
    if (unlockTimer) clearTimeout(unlockTimer)
    unlockTimer = setTimeout(() => {
        unlockTimer = null
        // When the lock expires, pick up whatever id the parent is showing now.
        // pendingId may already be stale — props.jurisdictionId is the source of truth.
        const next = props.jurisdictionId
        if (next && next !== currentlyLoadedId) {
            loadLayers(next)
        }
        pendingId = null
    }, Math.max(0, remainingMs))
}

function requestLoad(id) {
    // If nothing's loaded yet (cold start) or the id really didn't change, no-op.
    if (!id) {
        destroyLayers()
        currentlyLoadedId = null
        pendingId = null
        status.value = 'idle'
        if (unlockTimer) { clearTimeout(unlockTimer); unlockTimer = null }
        return
    }
    if (id === currentlyLoadedId) return

    // A fetch sequence is in flight — don't preempt it. Just remember the
    // newest id; markLoaded() will schedule the unlock once it finishes.
    if (isLoading) {
        pendingId = id
        return
    }

    // First ever load → go immediately. No point waiting on a blank screen.
    if (!currentlyLoadedId) {
        loadLayers(id)
        return
    }

    const since = Date.now() - lastLoadAt
    if (since >= props.minDisplayMs) {
        loadLayers(id)
    } else {
        pendingId = id
        scheduleUnlock(props.minDisplayMs - since)
    }
}

async function loadLayers(id) {
    if (!map) return
    const myLoad = ++activeLoadId
    if (unlockTimer) { clearTimeout(unlockTimer); unlockTimer = null }
    destroyLayers()
    currentlyLoadedId = id
    pendingId = null
    isLoading = true

    if (!id) {
        status.value = 'idle'
        isLoading = false
        return
    }

    status.value = 'loading'
    errorMsg.value = ''

    try {
        // precise=1 → unsimplified polygon. Lets the user verify visually that
        // the geoBoundaries outline and the WorldPop raster overlay agree at
        // full resolution. The PNG is always clipped at the full-res polygon,
        // so the outline must be too or coastline mismatches look like data
        // bugs when they're really simplification artifacts.
        const geoRes = await fetch(`/api/jurisdictions/${id}/self.geojson?precise=1`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        if (myLoad !== activeLoadId) return
        if (!geoRes.ok) throw new Error(`polygon HTTP ${geoRes.status}`)
        const geo = await geoRes.json()
        if (myLoad !== activeLoadId) return

        if (!geo?.features?.length || !geo.features[0].geometry) {
            status.value = 'empty'
            markLoaded()
            return
        }

        polygonLayer = L.geoJSON(geo, {
            style: {
                color:       '#60a5fa',
                weight:      2,
                fill:        true,
                fillColor:   '#1e293b',
                fillOpacity: 0.25,
            },
        }).addTo(map)

        const bounds = polygonLayer.getBounds()
        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [6, 6], animate: false })
        }
        status.value = 'polygon'

        // P.1.1: skip raster overlay when the parent says we shouldn't.
        // During the geoboundaries phase, raster tiles from a previous run
        // may still be present in worldpop_rasters and would render as a
        // misleading "this country already has population data" overlay.
        if (!props.showRaster) {
            markLoaded()
            return
        }

        const rastRes = await fetch(`/api/jurisdictions/${id}/raster.png`, {
            credentials: 'same-origin',
        })
        if (myLoad !== activeLoadId) return

        if (rastRes.status === 204) {
            // no raster loaded for this country — polygon-only is fine
            markLoaded()
            return
        }
        if (!rastRes.ok) throw new Error(`raster HTTP ${rastRes.status}`)

        const blob = await rastRes.blob()
        if (myLoad !== activeLoadId) { return }

        rasterBlobUrl = URL.createObjectURL(blob)
        rasterLayer = L.imageOverlay(rasterBlobUrl, bounds, {
            opacity:     0.75,
            interactive: false,
        })
        // Insert raster BELOW polygon so outline stays on top.
        rasterLayer.addTo(map)
        if (polygonLayer) polygonLayer.bringToFront()
        status.value = 'overlay'
        markLoaded()
    } catch (e) {
        if (myLoad !== activeLoadId) return
        status.value = 'error'
        errorMsg.value = String(e?.message || e)
        // Still lock — a 500 on a huge country shouldn't make the UI retry
        // every 2 s. User can reload manually if needed.
        markLoaded()
    }
}

function markLoaded() {
    lastLoadAt = Date.now()
    isLoading = false
    // If the parent already advanced past the id we just finished rendering,
    // schedule an unlock so we pick up the newer id after minDisplayMs.
    if (props.jurisdictionId && props.jurisdictionId !== currentlyLoadedId) {
        scheduleUnlock(props.minDisplayMs)
    }
}

onMounted(() => {
    map = L.map(mapContainer.value, {
        zoomControl:   false,
        attributionControl: false,
        scrollWheelZoom: false,
        dragging:        false,
        doubleClickZoom: false,
        touchZoom:       false,
        boxZoom:         false,
        keyboard:        false,
        tap:             false,
    }).setView([0, 0], 2)

    // No base tiles — just the polygon + raster on a dark backdrop.
    if (props.jurisdictionId) requestLoad(props.jurisdictionId)
})

onBeforeUnmount(() => {
    activeLoadId++
    if (unlockTimer) { clearTimeout(unlockTimer); unlockTimer = null }
    destroyLayers()
    if (map) { map.remove(); map = null }
})

watch(() => props.jurisdictionId, (id) => requestLoad(id))
</script>

<template>
    <div class="relative w-full h-full">
        <div ref="mapContainer" class="absolute inset-0 rounded-md bg-gray-950" />
        <div
            v-if="status === 'idle'"
            class="absolute inset-0 flex items-center justify-center text-gray-500 text-xs"
        >
            Preparing next jurisdiction…
        </div>
        <div
            v-else-if="status === 'loading'"
            class="absolute inset-0 flex items-center justify-center text-gray-500 text-xs"
        >
            Loading map…
        </div>
        <div
            v-else-if="status === 'empty'"
            class="absolute inset-0 flex items-center justify-center text-gray-600 text-xs italic"
        >
            No geometry available
        </div>
        <div
            v-else-if="status === 'polygon'"
            class="absolute bottom-1 right-1 text-[10px] bg-gray-900/80 border border-gray-700 rounded px-1.5 py-0.5 text-gray-400"
        >
            raster not loaded
        </div>
        <div
            v-if="status === 'error'"
            class="absolute bottom-1 left-1 right-1 text-[10px] bg-red-900/80 border border-red-700 rounded px-1.5 py-0.5 text-red-200 truncate"
            :title="errorMsg"
        >
            {{ errorMsg }}
        </div>
    </div>
</template>

<style scoped>
:deep(.leaflet-container) {
    background: #020617;
}
</style>
