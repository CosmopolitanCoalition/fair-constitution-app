<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase H (H0 substrate) — cross-border companion to population_within().
 *
 * `population_within(iso, geom, year)` is single-iso. A drawn/split district
 * inside a giant near a border, or inside a union scope, can straddle several
 * country rasters. WorldPop ships PER-COUNTRY tiles that OVERLAP at borders;
 * a naive SUM across countries would double-count the seam.
 *
 * `population_within_multi(geom, year)` resolves every intersecting country
 * raster and de-duplicates the overlap by unioning the clipped tiles with
 * MAX-per-pixel BEFORE summing — the same posture as RasterTileController's
 * ST_Union and the ETL population-correction passes (design §3.3 / §4.2.2).
 * WorldPop's 100 m rasters sit on a single consistent global grid, so tiles
 * from different countries are alignment-compatible for ST_Union.
 *
 * Returns a BIGINT aggregate population — never individual records (§5 P1).
 * `worldpop_rasters` is untouched (additive: new function only).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_within_multi(
                p_geom GEOMETRY,
                p_year SMALLINT DEFAULT 2023
            ) RETURNS BIGINT AS $$
                WITH s AS MATERIALIZED (
                    -- Mirror population_within(): ST_MakeValid for everyone (idempotent
                    -- on valid input), conditional simplify for million-vertex giants.
                    SELECT ST_MakeValid(
                        CASE
                            WHEN ST_NPoints(p_geom) > 50000
                            THEN ST_SimplifyPreserveTopology(p_geom, 0.001)
                            ELSE p_geom
                        END
                    ) AS geom
                ),
                clipped AS (
                    SELECT ST_Clip(r.rast, s.geom, TRUE) AS rast
                    FROM   worldpop_rasters r CROSS JOIN s
                    WHERE  r.year = p_year
                      AND  ST_Intersects(r.rast, s.geom)
                )
                SELECT COALESCE(
                    ROUND(
                        -- MAX-per-pixel union de-dups border-overlap pixels (two
                        -- country rasters covering the same cell), then sum.
                        (ST_SummaryStats(ST_Union(rast, 'MAX'))).sum
                    )::BIGINT,
                    0
                )
                FROM clipped;
            $$ LANGUAGE SQL STABLE;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS population_within_multi(GEOMETRY, SMALLINT)');
    }
};
