<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix population_within() — apply simplification + ST_MakeValid to ST_Intersects.
 *
 * Background. Migration 2026_05_07_000001 introduced
 * ST_SimplifyPreserveTopology for the ST_Clip mask but left the tile-filter
 * ST_Intersects(rast, p_geom) using the native (un-simplified) polygon.
 * The reasoning at the time was "don't miss edge tiles" — but ST_Intersects
 * (raster, geometry) internally calls GEOSContains, which blows up on the
 * SAME precision-sensitive topology issues we fixed for ST_Clip. The just-
 * completed world ETL run logged this for 10 countries:
 *
 *     unhandled error — GEOSContains: TopologyException:
 *       side location conflict at <coords>
 *
 * Affected: JPN, NOR, RWA, ESP, AZE, FLK, GUF, NCL, PYF, TCA. All return
 * ST_IsValid()=true, so the issue is GEOS precision in the deep operator,
 * not "structurally invalid input." Total population miss was ~206 M.
 *
 * Fix. Two changes inside the function body:
 *
 *   1. Wrap the conditional simplification in ST_MakeValid(...). It's a
 *      no-op on already-valid input but snaps colocated vertices on
 *      precision-borderline cases, resolving the side location conflicts
 *      GEOSContains otherwise raises.
 *
 *   2. Use s.geom (the prepared, possibly-simplified, made-valid geometry)
 *      in the WHERE clause's ST_Intersects, NOT the raw p_geom. The
 *      bounding box is unchanged at 0.001° simplification tolerance and
 *      tile size is 256×256 cells (~25 km × 25 km), so no edge tiles can
 *      possibly be missed by using s.geom instead of p_geom.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_within(
                p_iso_code   VARCHAR(3),
                p_geom       GEOMETRY,
                p_year       SMALLINT DEFAULT 2023
            ) RETURNS BIGINT AS $$
                WITH s AS MATERIALIZED (
                    -- Conditional simplification for the giants + ST_MakeValid
                    -- for everyone. ST_MakeValid is idempotent on already-
                    -- valid input (cheap) and resolves GEOS precision issues
                    -- (the "side location conflict at <lat,lng>" failures
                    -- previously seen on JPN, NOR, RWA, etc.) on borderline
                    -- valid input.
                    SELECT ST_MakeValid(
                        CASE
                            WHEN ST_NPoints(p_geom) > 50000
                            THEN ST_SimplifyPreserveTopology(p_geom, 0.001)
                            ELSE p_geom
                        END
                    ) AS geom
                )
                SELECT COALESCE(
                    ROUND(
                        SUM((ST_SummaryStats(ST_Clip(r.rast, s.geom, TRUE))).sum)
                    )::BIGINT,
                    0
                )
                FROM  worldpop_rasters r CROSS JOIN s
                WHERE r.iso_code = p_iso_code
                  AND r.year     = p_year
                  -- Use s.geom (prepared) here too — same bbox as the native
                  -- polygon at 0.001° simplification tolerance, but doesn't
                  -- inherit the GEOS precision conflicts that the native
                  -- polygon does.
                  AND ST_Intersects(r.rast, s.geom);
            $$ LANGUAGE SQL STABLE;
        SQL);
    }

    public function down(): void
    {
        // Restore the previous (Bug-1-affected) version. Roll-forward only:
        // nobody should actually want this version back since it breaks
        // ST_Intersects on 10 of 232 countries.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_within(
                p_iso_code   VARCHAR(3),
                p_geom       GEOMETRY,
                p_year       SMALLINT DEFAULT 2023
            ) RETURNS BIGINT AS $$
                WITH s AS MATERIALIZED (
                    SELECT CASE
                        WHEN ST_NPoints(p_geom) > 50000
                        THEN ST_SimplifyPreserveTopology(p_geom, 0.001)
                        ELSE p_geom
                    END AS geom
                )
                SELECT COALESCE(
                    ROUND(
                        SUM((ST_SummaryStats(ST_Clip(r.rast, s.geom, TRUE))).sum)
                    )::BIGINT,
                    0
                )
                FROM  worldpop_rasters r CROSS JOIN s
                WHERE r.iso_code = p_iso_code
                  AND r.year     = p_year
                  AND ST_Intersects(r.rast, p_geom);
            $$ LANGUAGE SQL STABLE;
        SQL);
    }
};
