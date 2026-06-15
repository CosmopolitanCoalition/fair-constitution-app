<?php

namespace App\Services\Districting;

use App\Services\ConstitutionalDefaults;
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
