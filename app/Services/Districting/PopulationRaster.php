<?php

namespace App\Services\Districting;

use App\Services\ConstitutionalDefaults;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase H (H0) — THE runtime PHP entry point to the WorldPop raster.
 *
 * `population_within(iso, geom, year)` and `population_within_multi(geom, year)`
 * are fully built SQL functions, but until now they had NO PHP runtime caller —
 * they were used only by ETL/migration population-correction passes that seed
 * `jurisdictions.population`; the runtime districting path reads that STORED
 * integer. The manual-draw and shortest-splitline tools are the first runtime
 * consumers: they need the population inside an ARBITRARY drawn/cut polygon.
 * This service is the missing seam (design §4.3).
 *
 * It returns only AGGREGATE population (a BIGINT sum at 100 m resolution) —
 * never raw locations or individual records (§5 P1 / P2). Callers gate access
 * (R-08) and floor tiny polygons before exposing a count to a human.
 */
class PopulationRaster
{
    /**
     * Aggregate population inside a polygon, within one country's rasters.
     * $geoJson is a GeoJSON geometry string (EPSG:4326, as the draw tool emits).
     */
    public function populationWithin(string $iso, string $geoJson, int $year = 2023): int
    {
        $row = DB::selectOne(
            'SELECT population_within(?, ST_GeomFromGeoJSON(?), ?::smallint) AS pop',
            [$iso, $geoJson, $year]
        );

        return (int) ($row->pop ?? 0);
    }

    /**
     * Aggregate population inside a polygon across every intersecting country
     * raster, de-duplicating border-overlap pixels (MAX-per-pixel). Use this for
     * cut/drawn shapes that may straddle a border or sit in a union scope.
     */
    public function populationWithinMulti(string $geoJson, int $year = 2023): int
    {
        $row = DB::selectOne(
            'SELECT population_within_multi(ST_GeomFromGeoJSON(?), ?::smallint) AS pop',
            [$geoJson, $year]
        );

        return (int) ($row->pop ?? 0);
    }

    /**
     * The giant's WorldPop pixels as a flat [[lng, lat, people], ...] grid,
     * clipped to its boundary and de-duplicated across border rasters — computed
     * ONCE and cached. This is what makes the split-line tools interactive: every
     * candidate cut (manual line OR an automatic shortest-split-line sweep) is
     * then an O(pixels) blade-side sum in PHP instead of a fresh PostGIS raster
     * scan per cut. Aggregate-only (100 m pixel sums) — never individual records.
     *
     * Sized for the childless-giant case (a castello/county = a few thousand
     * pixels). Earth-class giants would use the queued path (not this).
     */
    public function pixelGrid(string $scopeId, int $year = 2023): array
    {
        return Cache::rememberForever("districting.pixelgrid.{$scopeId}.{$year}", function () use ($scopeId, $year) {
            // Per-tile clip + pixel-centroids of the giant's OWN country raster —
            // NO ST_Union (unioning border-overlapping tiles is ~500× slower). A
            // childless leaf giant sits inside one country, so iso_code suffices;
            // ~1.5k pixels for a castello, ~0.4s once, then cached. (A rare
            // cross-border giant would need the multi path — out of scope here.)
            $rows = DB::select(
                "WITH g AS (SELECT ST_MakeValid(geom) AS geom, iso_code FROM jurisdictions WHERE id = ?)
                 SELECT ST_X(pc.geom) AS x, ST_Y(pc.geom) AS y, pc.val AS val
                   FROM worldpop_rasters r
                   CROSS JOIN g
                   CROSS JOIN LATERAL ST_PixelAsCentroids(ST_Clip(r.rast, g.geom, TRUE), 1) AS pc
                  WHERE r.iso_code = g.iso_code
                    AND r.year = ?::smallint
                    AND ST_Intersects(r.rast, g.geom)
                    AND pc.val > 0",
                [$scopeId, $year]
            );

            return array_map(fn ($r) => [(float) $r->x, (float) $r->y, (float) $r->val], $rows);
        });
    }

    /**
     * Split a pixel grid by a directed blade A→B: returns [popLeft, popRight] by
     * the sign of the cross product (which side of the line each pixel sits on).
     * O(pixels), no database — the hot path of every split evaluation.
     */
    public static function splitByBlade(array $grid, float $ax, float $ay, float $bx, float $by): array
    {
        $dirx = $bx - $ax;
        $diry = $by - $ay;
        $left = 0.0;
        $right = 0.0;
        foreach ($grid as [$x, $y, $v]) {
            if ($dirx * ($y - $ay) - $diry * ($x - $ax) >= 0) {
                $left += $v;
            } else {
                $right += $v;
            }
        }

        return [$left, $right];
    }

    /**
     * The local quota: population per seat at a scope. A drawn piece's implied
     * fractional seats = its population / this quota (the same quota the
     * LegislatureController computes for the scope).
     */
    public function quota(int $population, int $seats): float
    {
        return $population / max($seats, 1);
    }

    /** Implied fractional seats for a population against a quota. */
    public function impliedSeats(int $population, float $quota): float
    {
        return $quota > 0 ? $population / $quota : 0.0;
    }

    /**
     * Whether a rounded seat count sits in the resolved band for a scope. The
     * band (default 5/9) is amendable per jurisdiction — resolved, never literal.
     */
    public function inBand(int $seats, ?string $jurisdictionId = null): bool
    {
        $floor   = ConstitutionalDefaults::floor($jurisdictionId);
        $ceiling = ConstitutionalDefaults::ceiling($jurisdictionId);

        return $seats >= $floor && $seats <= $ceiling;
    }
}
