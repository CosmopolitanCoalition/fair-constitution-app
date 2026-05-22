<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase L — make population_within() memory-safe on native-vertex giants.
 *
 * Background. The original definition (migration 000010) called
 * ST_Clip(rast, p_geom, TRUE) per overlapping raster tile. With Phase L's
 * native-vertex jurisdictions.geom storage, polygons like Nunavut land in
 * the function with ~5 million vertices. ST_Clip materialises a polygon
 * mask per tile, and walking 5 M edges across thousands of tiles spikes
 * postgres operator memory past the container cap — the kernel OOM-kills
 * the backend and the ETL fails on CAN-adm2.
 *
 * Fix — conditional simplification. We do NOT simplify by default. The vast
 * majority of features (counties, municipalities, even most provinces) have
 * < 50 k vertices and ST_Clip handles them comfortably under any reasonable
 * memory budget — full native precision is preserved. Only when the input
 * polygon crosses a vertex threshold that would risk OOM do we apply
 * ST_Simplify at ~100 m tolerance for the clip mask. WorldPop is a 100 m
 * raster, so any finer precision on the mask is wasted — the population
 * sum at sub-100m boundary precision is identical within rounding.
 *
 * Threshold rationale (50,000 vertices). Empirical vertex distribution from
 * a Phase-L-loaded world: ~98 % of features have ≤ 50 k vertices, ~99.8 %
 * have ≤ 100 k. Per-call ST_Clip memory scales linearly with vertex count;
 * 50 k peaks at ~50 MB / call, comfortable under the postgres container's
 * 4 GB operator headroom even with parallel workers. The handful of
 * outliers (Nunavut at 5 M, Russia regions, Greenland, etc.) get simplified
 * — they're the ones that OOM otherwise.
 *
 * Tile filter (ST_Intersects in WHERE) keeps using the NATIVE polygon so
 * we don't miss any tile that genuinely overlaps the un-simplified shape;
 * only the per-tile clip uses the simplified version.
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
                    -- Conditional simplification: pass-through for normal
                    -- features (full precision), simplify only when the
                    -- vertex count would risk ST_Clip OOM. ST_NPoints is
                    -- cheap (single polygon walk) — the cost is negligible
                    -- compared to the per-tile ST_Clip downstream.
                    SELECT CASE
                        WHEN ST_NPoints(p_geom) > 50000
                        THEN ST_Simplify(p_geom, 0.001)
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
                  -- Tile filter uses the native polygon: the bbox check is
                  -- GIST-indexed and cheap, and we don't want to miss a
                  -- tile that the native polygon catches but a simplified
                  -- copy might miss at the boundary.
                  AND ST_Intersects(r.rast, p_geom);
            $$ LANGUAGE SQL STABLE;
        SQL);
    }

    public function down(): void
    {
        // Restore the original Phase-L-pre-existing definition.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_within(
                p_iso_code   VARCHAR(3),
                p_geom       GEOMETRY,
                p_year       SMALLINT DEFAULT 2023
            ) RETURNS BIGINT AS $$
                SELECT COALESCE(
                    ROUND(
                        SUM((ST_SummaryStats(ST_Clip(rast, p_geom, TRUE))).sum)
                    )::BIGINT,
                    0
                )
                FROM  worldpop_rasters
                WHERE iso_code = p_iso_code
                  AND year     = p_year
                  AND ST_Intersects(rast, p_geom);
            $$ LANGUAGE SQL STABLE;
        SQL);
    }
};
