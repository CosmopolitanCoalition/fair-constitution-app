/**
 * lib/protomapsBasemap — the ONE way a Leaflet map on this app gets its
 * Protomaps cartography (2026-09-10: the residency page's maps rendered no
 * basemap because they called leafletLayer with only a url and a flavor; the
 * layer needs paintRules and labelRules built FROM the flavor, or it paints
 * nothing, silently. This mirrors the jurisdiction viewer's construction).
 *
 * Lookup order for the PMTiles bundle, first match wins:
 *   1. the latest dated bundle in the operator's protomaps directory, via
 *      GET /api/maps/latest-pmtiles;
 *   2. the legacy fixed file at /maps/world.pmtiles;
 *   3. a remote URL from VITE_PROTOMAPS_URL.
 * Nothing configured -> returns false and the map stays polygon-only.
 *
 * Tiles are streamed by HTTP range requests, so a 135 GB planet bundle costs
 * the browser only the tiles it looks at.
 */
export async function addProtomapsBasemap(map, { paneZIndex = 150, flavorName = 'light' } = {}) {
    try {
        const protomaps = await import('protomaps-leaflet');
        const basemaps = await import('@protomaps/basemaps');

        let url = null;
        try {
            const res = await fetch('/api/maps/latest-pmtiles', { credentials: 'same-origin' });
            if (res.ok) {
                const data = await res.json();
                if (data?.url) url = data.url;
            }
        } catch { /* fall through */ }
        if (!url) {
            try {
                const head = await fetch('/maps/world.pmtiles', { method: 'HEAD' });
                if (head.ok) url = '/maps/world.pmtiles';
            } catch { /* ignore */ }
        }
        if (!url) {
            const remote = import.meta.env?.VITE_PROTOMAPS_URL || '';
            if (remote) url = remote;
        }
        if (!url) return false;

        if (!map.getPane('basemapPane')) {
            map.createPane('basemapPane');
            map.getPane('basemapPane').style.zIndex = paneZIndex;
        }
        const lang = (navigator.language || 'en').split('-')[0].toLowerCase();
        const flavor = basemaps.namedFlavor(flavorName);
        protomaps
            .leafletLayer({
                url,
                paintRules: protomaps.paintRules(flavor),
                labelRules: protomaps.labelRules(flavor, lang),
                lang,
                pane: 'basemapPane',
                attribution: 'Basemap © <a href="https://protomaps.com">Protomaps</a> · © OpenStreetMap',
            })
            .addTo(map);
        return true;
    } catch (e) {
        console.warn('Protomaps basemap unavailable — polygon-only map:', e);
        return false;
    }
}
