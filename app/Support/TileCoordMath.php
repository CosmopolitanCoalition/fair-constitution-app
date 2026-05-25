<?php

namespace App\Support;

/**
 * Single source of truth for Web Mercator (slippy-map) tile-coord ↔ lat/lng
 * math used across the raster-tile pipeline.
 *
 * Three call sites converge here:
 *
 *   - RasterTileController::tile()          → tileBounds() to compute
 *                                              EPSG:4326 bbox for a tile.
 *   - RasterTilePrewarmCommand::landMask()  → bboxToTileKeys() to enumerate
 *                                              candidate tiles overlapping
 *                                              each worldpop_rasters row.
 *   - TileCacheInvalidator::invalidateBbox  → bboxToTileKeys() to enumerate
 *                                              cache entries an edit touched
 *                                              (built in Phase T.4).
 *
 * Centralising here means a fix (e.g. an off-by-one at the antimeridian, a
 * different polar clamp) propagates to all three with no risk of drift.
 *
 * All methods match Leaflet's default L.TileLayer XYZ scheme:
 *
 *   - x grows east  (0 at lng = -180°, 2^z - 1 at lng = +180°)
 *   - y grows south (0 at lat ≈ +85.0511°, 2^z - 1 at lat ≈ -85.0511°)
 *   - Mercator divergence point ±85.0511° is the standard slippy-map clamp.
 */
final class TileCoordMath
{
    /** Standard slippy-map Mercator divergence clamp (degrees). */
    public const MERCATOR_LAT_LIMIT = 85.0511;

    /**
     * XYZ tile coord → EPSG:4326 lat/lng bounds.
     *
     * Returns an associative array with keys 'west', 'east', 'north', 'south'
     * (all in degrees). Matches Leaflet's default L.TileLayer scheme — the
     * tile rendered at (z, x, y) covers exactly this bbox.
     */
    public static function tileBounds(int $z, int $x, int $y): array
    {
        $n = 2 ** $z;
        return [
            'west'  => $x / $n * 360.0 - 180.0,
            'east'  => ($x + 1) / $n * 360.0 - 180.0,
            'north' => rad2deg(atan(sinh(M_PI - 2.0 * M_PI * $y / $n))),
            'south' => rad2deg(atan(sinh(M_PI - 2.0 * M_PI * ($y + 1) / $n))),
        ];
    }

    /**
     * Latitude (degrees) → tile y at zoom z (Web Mercator).
     *
     * Clamps to ±85.0511° (the Mercator divergence point where the projection
     * blows up at the poles). Returns an integer in [0, 2^z - 1].
     *
     * Note: in slippy-map convention, NORTHERN latitudes map to SMALLER y.
     * So latToTileY(60°, z) < latToTileY(-60°, z).
     */
    public static function latToTileY(float $lat, int $z): int
    {
        $clampedLat = max(-self::MERCATOR_LAT_LIMIT, min(self::MERCATOR_LAT_LIMIT, $lat));
        $latRad     = deg2rad($clampedLat);
        $n          = 1 << $z;
        $yNorm      = (1.0 - log(tan($latRad) + 1.0 / cos($latRad)) / M_PI) / 2.0;
        return max(0, min($n - 1, (int) floor($yNorm * $n)));
    }

    /**
     * Longitude (degrees) → tile x at zoom z.
     *
     * Inputs outside ±180° are clamped to the world bbox. Returns an
     * integer in [0, 2^z - 1].
     */
    public static function lngToTileX(float $lng, int $z): int
    {
        $clampedLng = max(-180.0, min(180.0, $lng));
        $n          = 1 << $z;
        return max(0, min($n - 1, (int) floor(($clampedLng + 180.0) / 360.0 * $n)));
    }

    /**
     * EPSG:4326 bbox → set of (z, x, y) tile keys covering it across
     * [minZ, maxZ].
     *
     * Returns an associative array keyed by "z/x/y" with value true, so
     * callers get O(1) membership tests and natural deduplication when
     * unioning across multiple input bboxes.
     *
     * Inclusive on both axes — any tile whose bbox touches the input bbox
     * at all is included. The prewarm landmask deliberately over-covers
     * rather than miss edge tiles, and the cache-invalidator from T.4
     * needs the same behaviour (slight over-invalidation is safer than
     * stale tiles served after an edit).
     *
     * Inputs:
     *   $south, $west, $north, $east — lat/lng degrees. $south ≤ $north,
     *                                   $west  ≤ $east. (Antimeridian
     *                                   wraps are NOT handled here; the
     *                                   only known producer in this app is
     *                                   worldpop_rasters bboxes, which are
     *                                   per-iso clipped GeoTIFFs and never
     *                                   span the antimeridian.)
     *   $minZ, $maxZ                  — zoom range (inclusive on both ends).
     */
    public static function bboxToTileKeys(
        float $south, float $west,
        float $north, float $east,
        int $minZ, int $maxZ
    ): array {
        $keys = [];
        for ($z = $minZ; $z <= $maxZ; $z++) {
            $xMin = self::lngToTileX($west,  $z);
            $xMax = self::lngToTileX($east,  $z);
            // Slippy-map y inverts latitude — north (larger lat) gives
            // smaller y, south (smaller lat) gives larger y.
            $yMin = self::latToTileY($north, $z);
            $yMax = self::latToTileY($south, $z);
            for ($x = $xMin; $x <= $xMax; $x++) {
                for ($y = $yMin; $y <= $yMax; $y++) {
                    $keys["{$z}/{$x}/{$y}"] = true;
                }
            }
        }
        return $keys;
    }
}
