<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ANTIMERIDIAN GUARD (2026-07-21, run-6 review class antimeridian-xx000).
 *
 * PROTECTED SURFACE — population_within_multi ships in the baseline schema
 * (database/schema/pgsql-schema.sql) and is on the constitutional-review
 * list. This change was operator-sanctioned 2026-07-21 ("fix the issues with
 * the stack pending review") and probe-verified read-only against all four
 * failing date-line leaves before shipping: FJI tikinas 30100110/120/130/200
 * each measured to their EXACT stored population (1366, 885, 962, 1174) via
 * the split-and-sum below, and a non-crossing control (30100210) returned a
 * bit-identical result through the guarded path.
 *
 * The bug: a date-line geometry (bbox width > 180°) clips raster fragments
 * at BOTH x ≈ -180 and x ≈ +180; ST_Union(rast, 'MAX') then allocates ONE
 * mosaic spanning ~360° ≈ 432,000 columns — over the 65535 rt_raster_new
 * hard limit (SQLSTATE XX000). The sweep died at its first touch of the
 * scope (coverageStats), so the four leaves could never even be planned.
 *
 * The fix: such geometries split at longitude 0 into western/eastern halves;
 * each half runs the IDENTICAL clip + MAX-per-pixel-union pipeline (border
 * de-dup preserved within each half), raw sums add, ONE round at the end.
 * No ST_ShiftLongitude anywhere — rasters stay in native -180..180 tile
 * space, which shifted geometry would miss. The cut at 0 is safe under the
 * >180° guard: a genuine date-line geometry has no mass near 0°, the halves
 * are disjoint envelopes, and each clipped half carries a tight bbox.
 * Non-crossing input takes the EXACT previous path.
 *
 * The baseline dump absorbs this body at the next operator-signed re-flatten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.population_within_multi(p_geom public.geometry, p_year smallint DEFAULT 2023) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
        -- Antimeridian guard (2026-07-21): see the migration of the same
        -- date. Width ≤ 180° → one part = raw p_geom (the exact previous
        -- pipeline); width > 180° → western/eastern halves, same pipeline
        -- per half, raw sums added, one round at the end.
        WITH parts AS MATERIALIZED (
            SELECT row_number() OVER () AS part_id,
                   ST_MakeValid(
                       CASE
                           WHEN ST_NPoints(q.g) > 50000
                           THEN ST_SimplifyPreserveTopology(q.g, 0.001)
                           ELSE q.g
                       END
                   ) AS geom
            FROM (
                SELECT p_geom AS g
                WHERE COALESCE(ST_XMax(p_geom) - ST_XMin(p_geom), 0) <= 180
                UNION ALL
                SELECT ST_CollectionExtract(
                           ST_Intersection(ST_MakeValid(p_geom),
                                           ST_MakeEnvelope(-180, -90, 0, 90, 4326)), 3)
                WHERE ST_XMax(p_geom) - ST_XMin(p_geom) > 180
                UNION ALL
                SELECT ST_CollectionExtract(
                           ST_Intersection(ST_MakeValid(p_geom),
                                           ST_MakeEnvelope(0, -90, 180, 90, 4326)), 3)
                WHERE ST_XMax(p_geom) - ST_XMin(p_geom) > 180
            ) q
            WHERE q.g IS NOT NULL AND NOT ST_IsEmpty(q.g)
        ),
        per_part AS (
            -- MAX-per-pixel union de-dups border-overlap pixels (two
            -- country rasters covering the same cell) WITHIN each part —
            -- identical to the previous single-part body.
            SELECT p.part_id,
                   (ST_SummaryStats(ST_Union(c.rast, 'MAX'))).sum AS part_sum
            FROM parts p
            CROSS JOIN LATERAL (
                SELECT ST_Clip(r.rast, p.geom, TRUE) AS rast
                FROM   worldpop_rasters r
                WHERE  r.year = p_year
                  AND  ST_Intersects(r.rast, p.geom)
            ) c
            GROUP BY p.part_id
        )
        SELECT COALESCE(ROUND(SUM(part_sum))::BIGINT, 0) FROM per_part;
    $$;
SQL);
    }

    public function down(): void
    {
        // The original baseline body, verbatim (pgsql-schema.sql).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.population_within_multi(p_geom public.geometry, p_year smallint DEFAULT 2023) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
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
    $$;
SQL);
    }
};
