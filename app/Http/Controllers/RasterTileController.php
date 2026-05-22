<?php

namespace App\Http\Controllers;

use App\Support\TileCoordMath;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;

/**
 * RasterTileController — serves WorldPop population density as a Leaflet
 * TileLayer at GET /api/rasters/{z}/{x}/{y}.png.
 *
 * Replaces the prior per-jurisdiction ImageOverlay
 * (JurisdictionController::rasterPng), which had three structural problems:
 *
 *   - Flat 512-px resolution cap → ~21 km/px on USA-class polygons, visibly
 *     coarse vs vector-precise polygon outlines.
 *   - Bbox mismatch: PNG built from ST_Envelope(geom) on the unsimplified
 *     polygon, but the frontend placed the ImageOverlay using bounds
 *     derived from a zoom-simplified GeoJSON. Subtle pixel offset (the
 *     visible "smearing").
 *   - Earth scope returned 204 because planet has no iso_code; no
 *     composite world raster path existed.
 *
 * Tile model resolves all three by construction:
 *
 *   - Each tile's bounds are *defined by* its (z, x, y) coordinate; server
 *     and Leaflet agree on placement with zero geometry involvement.
 *   - Per-zoom resampling: low zooms aggressively downsample (cheap), high
 *     zooms (≤12) approach WorldPop's native 100 m resolution.
 *   - Earth is just z=0-2; same code path as any other zoom. The reference
 *     raster is built in EPSG:3857 (Web Mercator) so the rendered tile
 *     aligns with Leaflet's TileLayer projection naturally.
 *
 * No iso filter — the tile unions all overlapping worldpop_rasters rows,
 * which means dual-footprint regions (Taiwan, PRI, FRA overseas) render
 * correctly in the tile pixels regardless of which iso's jurisdiction row
 * the operator is viewing.
 *
 * Caching:
 *   - File-system cache at storage/app/tile-cache/{z}/{x}/{y}.png.
 *     Survives a full process lifecycle and a re-warm; purge by deleting
 *     the directory (or via the tile-cache:purge artisan command if added
 *     later).
 *   - Browser Cache-Control: public, max-age=86400 (1 day) — by tile coord
 *     so cross-jurisdiction reuse is automatic.
 *
 * Empty/ocean tiles return a 1×1 transparent PNG to keep cache files tiny.
 */
class RasterTileController extends Controller
{
    private const TILE_PX     = 256;
    private const MAX_ZOOM    = 12;
    private const CACHE_DIR   = 'tile-cache';
    private const BROWSER_TTL = 86400; // 1 day

    public function tile(Request $request, int $z, int $x, int $y): HttpResponse
    {
        if ($z < 0 || $z > self::MAX_ZOOM) {
            return response('', 404);
        }
        $maxIndex = (1 << $z) - 1;
        if ($x < 0 || $x > $maxIndex || $y < 0 || $y > $maxIndex) {
            return response('', 404);
        }

        // ── Disk cache fast-path ───────────────────────────────────────
        $cacheFullPath = storage_path(
            'app/' . self::CACHE_DIR . "/{$z}/{$x}/{$y}.png"
        );
        if (is_file($cacheFullPath)) {
            return $this->servePng((string) file_get_contents($cacheFullPath));
        }

        // ── Compute geographic bounds for this tile ────────────────────
        $bounds = TileCoordMath::tileBounds($z, $x, $y);

        // ── Fast existence check ──────────────────────────────────────
        // Skip the expensive resample/union pipeline when no rasters
        // overlap this tile (oceans, polar regions, etc.).
        $hasRaster = DB::selectOne(
            'SELECT 1 AS x FROM worldpop_rasters
             WHERE ST_Intersects(rast, ST_MakeEnvelope(:w, :s, :e, :n, 4326))
             LIMIT 1',
            [
                'w' => $bounds['west'], 's' => $bounds['south'],
                'e' => $bounds['east'], 'n' => $bounds['north'],
            ]
        );
        if (!$hasRaster) {
            $png = $this->transparentPng();
            $this->writeCache($cacheFullPath, $png);
            return $this->servePng($png);
        }

        // ── Generate the tile ─────────────────────────────────────────
        // PostGIS ships with only GTiff enabled by default; enable PNG for
        // this connection so ST_AsPNG can load the GDAL driver it needs.
        // Session-level (not LOCAL) — outside a transaction; the setting
        // is harmless if it leaks back to the connection pool.
        DB::statement("SET postgis.gdal_enabled_drivers = 'GTiff PNG JPEG'");

        // SQL renders the output in EPSG:3857 (Web Mercator) so the tile
        // aligns with how Leaflet projects the basemap and polygon
        // overlays. A naïve "ST_Transform every candidate raster to 3857
        // first" approach took ~130 s per z=4 tile because it reprojects
        // every native-resolution source. The version below resamples
        // sources into a small 4326 grid FIRST, then reprojects the tiny
        // unioned 256×256 raster to 3857 once. Same final result, ~5×
        // faster.
        //
        // Pipeline:
        //   1. bbox4326   = tile bbox in EPSG:4326 (used to GIST-filter sources)
        //   2. ref4326    = 256×256 grid in 4326 covering the tile bbox
        //   3. resampled  = source rasters downsampled into ref4326's grid
        //   4. unioned    = the resampled rasters merged (still 4326, small)
        //   5. framed     = unioned padded to exactly 256×256 of ref4326 extent
        //   6. ref3857    = 256×256 grid in 3857 covering the tile's WebMerc bbox
        //   7. warped     = framed reprojected to 3857, snapped to ref3857
        //   8. colored    = pseudocolor applied
        //   9. PNG bytes
        //
        // Threshold for the ocean-smear mask — interpolated as a literal
        // into the ST_MapAlgebra expression below since PDO can't bind
        // parameters inside SQL string literals. number_format with point
        // separator + no thousands separator → safe SQL float literal.
        $thresholdLit = number_format($this->smearThreshold($z), 4, '.', '');
        $row = DB::selectOne("
            WITH bbox4326 AS (
                SELECT ST_MakeEnvelope(:w, :s, :e, :n, 4326) AS geom
            ),
            ref4326 AS (
                -- Small 256×256 working grid in EPSG:4326. The band is
                -- required so ST_MapAlgebra below can use this as the
                -- canvas that forces the union to exactly 256×256.
                SELECT ST_AddBand(
                    ST_MakeEmptyRaster(
                        :tile_px::integer,
                        :tile_px::integer,
                        ST_XMin(geom)::double precision,
                        ST_YMax(geom)::double precision,
                        ((ST_XMax(geom) - ST_XMin(geom)) / :tile_px::double precision)::double precision,
                        (-1.0 * (ST_YMax(geom) - ST_YMin(geom)) / :tile_px::double precision)::double precision,
                        0::double precision,
                        0::double precision,
                        4326
                    ),
                    '32BF'::text,
                    0.0::double precision,
                    -3.4028235e+38::double precision  -- NODATA marker
                ) AS rast
                FROM bbox4326
            ),
            candidates AS (
                -- worldpop_rasters intersect the tile bbox; uses the
                -- GIST index. No iso_code filter — every overlapping
                -- raster contributes regardless of iso ownership.
                SELECT wr.rast
                FROM worldpop_rasters wr, bbox4326
                WHERE ST_Intersects(wr.rast, bbox4326.geom)
            ),
            resampled AS (
                -- Pre-resample each native-resolution source into the small
                -- ref4326 grid. Cheap because we're downsampling many small
                -- regions into 256×256 cells; same-SRID so ST_Resample is
                -- a pure pixel pick — no projection cost.
                --
                -- NearestNeighbor (not Average). Average mixes NODATA-aware
                -- land cells with ocean-NODATA neighbours by skipping the
                -- NODATA values entirely, which painted open ocean cells
                -- with coastal population values at low zooms (the
                -- Mediterranean / South Pacific / Atlantic blue-dots-in-
                -- water the operator saw at z=0-5). NearestNeighbor takes
                -- ONE source pixel per destination cell: if that pixel is
                -- NODATA, output is NODATA → transparent. Accurate
                -- representation of point-sampled density; dot frequency
                -- in the output naturally tracks land coverage instead of
                -- smearing across ocean.
                SELECT ST_Resample(c.rast, (SELECT rast FROM ref4326), 'NearestNeighbor'::text) AS rast
                FROM candidates c
            ),
            unioned AS (
                SELECT ST_Union(rast) AS rast FROM resampled WHERE rast IS NOT NULL
            ),
            framed AS (
                -- Pad the union to exactly 256×256 covering ref4326's
                -- bbox so the next step has a known-shape input even when
                -- source coverage is partial.
                SELECT ST_MapAlgebra(
                    (SELECT rast FROM ref4326),
                    1,
                    (SELECT rast FROM unioned),
                    1,
                    '[rast2]'::text,
                    '32BF'::text,
                    'FIRST'::text
                ) AS rast
            ),
            ref3857 AS (
                -- 256×256 EPSG:3857 grid covering the tile's Web Mercator
                -- bbox. The output snaps to this — Leaflet renders tile
                -- (z, x, y) at exactly the bbox this raster covers, so
                -- alignment with Protomaps base tiles + polygon overlay
                -- is bit-perfect.
                SELECT ST_MakeEmptyRaster(
                    :tile_px::integer,
                    :tile_px::integer,
                    ST_XMin(ST_Transform(b.geom, 3857))::double precision,
                    ST_YMax(ST_Transform(b.geom, 3857))::double precision,
                    ((ST_XMax(ST_Transform(b.geom, 3857)) - ST_XMin(ST_Transform(b.geom, 3857))) / :tile_px::double precision)::double precision,
                    (-1.0 * (ST_YMax(ST_Transform(b.geom, 3857)) - ST_YMin(ST_Transform(b.geom, 3857))) / :tile_px::double precision)::double precision,
                    0::double precision,
                    0::double precision,
                    3857
                ) AS rast
                FROM bbox4326 b
            ),
            warped AS (
                -- One ST_Transform on the small framed raster, then snap
                -- to ref3857 to enforce exact 256×256 output dimensions.
                -- NearestNeighbor (not Bilinear) for the snap so coastal
                -- pixels do not get averaged with neighbouring ocean
                -- pixels and visually leak population offshore.
                SELECT ST_Resample(
                    ST_Transform((SELECT rast FROM framed), 3857),
                    (SELECT rast FROM ref3857),
                    'NearestNeighbor'::text
                ) AS rast
            ),
            thresholded AS (
                -- Ocean-smear mask, zoom-scaled. WorldPop stores ocean
                -- pixels as NODATA, and 'Average' resampling EXCLUDES
                -- NODATA from its mean — so a dest pixel covering 1
                -- coastal land cell + 99 ocean cells emits the land
                -- cell's full value, painting open ocean as if it had
                -- coastal population. Worst at low zoom (each dest
                -- pixel covers thousands of km²); fades naturally by
                -- z=4+ where dest pixels are small enough that
                -- partial-land coverage is rare.
                --
                -- Threshold scales with zoom — masks tiny averaged
                -- smears at low zoom, leaves sparse rural data intact
                -- at high zoom. PDO can't bind parameters INSIDE a
                -- quoted SQL string, so the threshold value is
                -- interpolated into the expression string PHP-side
                -- (safe — it's a float constant we control, not user
                -- input).
                SELECT ST_MapAlgebra(
                    rast,
                    1,
                    '32BF',
                    'CASE WHEN [rast.val]::real < {$thresholdLit} THEN NULL ELSE [rast.val] END'::text
                ) AS rast
                FROM warped
            ),
            colored AS (
                SELECT ST_ColorMap(rast, 1, 'pseudocolor') AS rast FROM thresholded
                WHERE rast IS NOT NULL
            )
            SELECT ST_AsPNG(rast) AS png FROM colored
        ", [
            'w'       => $bounds['west'],
            's'       => $bounds['south'],
            'e'       => $bounds['east'],
            'n'       => $bounds['north'],
            'tile_px' => self::TILE_PX,
        ]);

        if (!$row || !$row->png) {
            $png = $this->transparentPng();
            $this->writeCache($cacheFullPath, $png);
            return $this->servePng($png);
        }

        // pgsql bytea returns either a stream resource or a hex-escaped
        // string depending on PDO settings; normalize to raw bytes.
        $png = is_resource($row->png) ? stream_get_contents($row->png) : $row->png;
        if (is_string($png) && str_starts_with($png, '\\x')) {
            $png = hex2bin(substr($png, 2));
        }

        if (!is_string($png) || $png === '') {
            $png = $this->transparentPng();
        }

        $this->writeCache($cacheFullPath, $png);
        return $this->servePng($png);
    }

    /**
     * Value floor for the population-mask. With the NearestNeighbor source
     * resampler the ocean-smear class of bug is gone — NODATA in, NODATA
     * out — so this threshold's only job is to mask source pixels whose
     * value is effectively zero (sub-1-person rounding artefacts) and
     * keep the colormap from painting them. Flat across zooms.
     */
    private function smearThreshold(int $z): float
    {
        return 1.0;
    }

    private function servePng(string $png): HttpResponse
    {
        return response($png, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=' . self::BROWSER_TTL,
        ]);
    }

    private function writeCache(string $fullPath, string $png): void
    {
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($fullPath, $png);
    }

    /**
     * 1×1 fully transparent PNG, base64-decoded once per request. Used for
     * tiles that don't overlap any worldpop_rasters row (ocean, polar
     * regions). Keeps cache files at minimum size while still letting
     * Leaflet's TileLayer cache the "no data here" response.
     */
    private function transparentPng(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII='
        );
        return $cached;
    }
}
