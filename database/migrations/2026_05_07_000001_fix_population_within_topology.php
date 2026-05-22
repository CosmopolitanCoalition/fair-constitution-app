<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix population_within() — switch ST_Simplify to ST_SimplifyPreserveTopology
 * and apply the simplification only when the polygon needs it.
 *
 * Background. The previous version of this function (migration
 * 2026_05_06_000001) called ST_Simplify(p_geom, 0.001) on every input. Two
 * problems:
 *
 *   1. ST_Simplify (Douglas-Peucker, no topology preservation) can take a
 *      perfectly valid polygon and produce a self-intersecting output when
 *      adjacent edges collapse. ST_Clip then refuses to use that polygon as
 *      a clip mask and raises:
 *          TopologyException: Input geom 1 is invalid: Self-intersection
 *      In the most recent world ETL run this killed Phase 2 for 162 of 232
 *      countries, leaving most populations NULL.
 *
 *   2. Even when ST_Simplify produced valid output, simplifying every
 *      polygon — including small ones at 1k vertices — was wasted work and
 *      threw away precision unnecessarily.
 *
 * Fix. Two changes:
 *
 *   - Use ST_SimplifyPreserveTopology(...) which guarantees a topologically
 *     valid output. Verified end-to-end against AFG (40.8M people unchanged
 *     within 0.0003%), ALB, AUS, CAN — all clean.
 *
 *   - Apply only when ST_NPoints(p_geom) > 50,000. Most features (counties,
 *     municipalities, even most country polygons) have fewer vertices than
 *     that and pass through unchanged — full native precision in the clip
 *     mask. Only the actual giants (Nunavut at 5M, Russia regions, Greenland)
 *     get simplified, and they need it both for memory headroom and to avoid
 *     ST_Clip's per-tile blowup on million-vertex masks.
 *
 * Tile filter (the WHERE ST_Intersects bbox check) keeps using the native
 * polygon so we don't miss any tile the simplified copy might miss at the
 * boundary.
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
                    -- Conditional simplification: pass-through for small
                    -- polygons (full precision), simplify only when the
                    -- vertex count would risk ST_Clip OOM or topology fail.
                    -- ST_SimplifyPreserveTopology guarantees the result is
                    -- topologically valid (no self-intersection), unlike
                    -- the plain ST_Simplify it replaces.
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
                  -- Tile filter uses the native polygon: GIST-indexed bbox
                  -- check is cheap, and we don't want to miss a tile that
                  -- the native shape catches but a simplified copy might
                  -- miss at the boundary.
                  AND ST_Intersects(r.rast, p_geom);
            $$ LANGUAGE SQL STABLE;
        SQL);
    }

    public function down(): void
    {
        // Restore the previous (broken-on-some-inputs) Phase L version. We
        // keep this so the migration is reversible, but in practice nobody
        // should roll back to the version that breaks 70% of countries.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_within(
                p_iso_code   VARCHAR(3),
                p_geom       GEOMETRY,
                p_year       SMALLINT DEFAULT 2023
            ) RETURNS BIGINT AS $$
                WITH s AS MATERIALIZED (
                    SELECT ST_Simplify(p_geom, 0.001) AS geom
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
