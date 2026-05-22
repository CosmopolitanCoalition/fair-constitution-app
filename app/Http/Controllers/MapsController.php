<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * MapsController — endpoints around map-asset selection (not tile generation).
 *
 * Tile generation for the WorldPop raster overlay lives in RasterTileController.
 * This controller handles asset selection for the *basemap* — finding which
 * Protomaps PMTiles bundle to serve as the cartographic backdrop under the
 * polygon layers.
 *
 * The basemap directory is bind-mounted at /var/www/html/public/maps/protomaps
 * from a host folder (default D:\fair-constitution-map-files\protomaps_pmtiles,
 * configurable via PROTOMAPS_DIR in .env). The operator drops new dated
 * .pmtiles files into that folder; this endpoint scans it and returns the
 * most recent filename for the frontend to load.
 */
class MapsController extends Controller
{
    /**
     * GET /api/maps/latest-pmtiles
     *
     * Scan public/maps/protomaps/ for *.pmtiles files and return the
     * lexicographically-latest one. The Protomaps team publishes weekly
     * builds with YYYYMMDD.pmtiles filenames; lexical sort matches date
     * order for that naming convention.
     *
     * Response:
     *   200 { url: "/maps/protomaps/20260512.pmtiles", filename, size, mtime }
     *        when at least one .pmtiles file is found
     *   200 { url: null }
     *        when the directory is missing or empty — frontend falls back to
     *        the legacy public/maps/world.pmtiles probe, then the
     *        VITE_PROTOMAPS_URL remote.
     *
     * Cached for 5 minutes via the response Cache-Control header so the
     * frontend doesn't re-scan on every map load, but a freshly-dropped
     * file becomes visible within a minute or two on next view.
     */
    public function latestPmtiles(): JsonResponse
    {
        $dir = public_path('maps/protomaps');
        if (! is_dir($dir)) {
            return $this->emptyResponse('protomaps directory not mounted');
        }

        // glob() can return false on permission errors; treat as empty.
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*.pmtiles') ?: [];
        if (empty($files)) {
            return $this->emptyResponse('no .pmtiles files in directory');
        }

        // Lexical descending sort works for YYYYMMDD-named files. For other
        // naming conventions, the operator can rename their bundle to a
        // higher-sorting name (e.g. zzz-mybundle.pmtiles) to force selection.
        rsort($files);
        $latestPath = $files[0];
        $latestName = basename($latestPath);

        return response()->json([
            'url'      => '/maps/protomaps/' . $latestName,
            'filename' => $latestName,
            'size'     => @filesize($latestPath) ?: 0,
            'mtime'    => @filemtime($latestPath) ?: null,
        ])->header('Cache-Control', 'public, max-age=300');
    }

    private function emptyResponse(string $reason): JsonResponse
    {
        return response()->json([
            'url'      => null,
            'filename' => null,
            'reason'   => $reason,
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
