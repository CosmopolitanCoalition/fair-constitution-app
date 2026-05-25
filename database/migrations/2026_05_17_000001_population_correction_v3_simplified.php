<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase T.8 — DB-on-DB correction with simplification + tile chunking
 *                  + cross-iso topological fallback.
 *
 * What broke previously.
 *   T.3 (piece-level via ST_Difference + ST_Dump) produced phantom-hairline
 *   overshoots on FRA L=5 (–928 k pre → +2.7 M post, 4× the real gap).
 *   T.4 added tile chunking (4° × 4° grid) and survived continental ISOs
 *   on the gap step but kept the overshoot mechanism. T.5 went full
 *   per-pixel via `ST_PixelAsCentroids` for correctness — that path is
 *   accurate but FRA L=5 was still running at 19 minutes when terminated,
 *   too slow for production. T.7 took the work out of PostGIS into
 *   in-memory NumPy on the GeoTIFFs and repeatedly OOM-killed inside the
 *   2 GB ETL container on CAN L=2 (16.5 M total vertices across 13
 *   polygons, Nunavut alone with 5.4 M vertices in 62,544 sub-polygons).
 *
 *   T.8 combines what each predecessor got right:
 *     - From the baseline `population_within` pass: conditional
 *       simplification (ST_NPoints > 50000 ⇒ ST_SimplifyPreserveTopology
 *       at 0.001°). Same precision regime as the proven baseline pass.
 *     - From T.4: per-tile chunking of the gap detection (4° × 4°
 *       envelopes over the iso's L=1 bbox). Each tile's ST_Union of
 *       intersecting L-polygons is bounded by tile area, not the
 *       continent's footprint. CAN's 175k sub-polygons across 13
 *       provinces never enter a single ST_Union — they're split across
 *       ~40 tiles, each tile unioning a handful of locally-intersecting
 *       L-polygons.
 *     - New in T.8: `population_within_topological` SQL helper. Wraps
 *       `population_within` with the same topological raster-fallback
 *       policy the Python `_topological_raster_fallback` uses for
 *       baseline injection (own iso first, then MAX over any iso whose
 *       raster footprint intersects the input geometry). Surfacing that
 *       fallback as SQL lets the correction pass use the same rules
 *       for overlap_pop and gap_pop computations — synthetic-intermediary
 *       siblings and gap pieces extending into foreign-raster regions
 *       get attributed via the right raster.
 *
 *   Operator-acknowledged tradeoff: simplification at the 50k-vertex
 *   threshold introduces a small precision bias on the giants. At
 *   WorldPop 100 m resolution, 0.001° (~110 m) is below pixel precision
 *   and zero-effect for everything under the threshold. The bias is
 *   explicitly accepted in exchange for production-feasible runtime.
 *
 * What this migration ships.
 *
 *   1. `population_within_topological(iso, geom, year)` SQL helper.
 *      Tries own-iso first; falls back to MAX over any other iso whose
 *      raster footprint intersects the input. Centralises the
 *      cross-iso raster matchup policy so overlap and gap calculations
 *      use the same rules as baseline injection.
 *
 *   2. `population_correction_pass(iso, level, year)` v3.
 *      CREATE OR REPLACE over the T.5 version. Same signature, same
 *      return shape (overlaps_detected, overlap_pop_total, gap_pieces,
 *      gap_pop_total, triple_overlap_warnings), so the Python
 *      orchestrator (`pixel_attribution_correction` in
 *      import_worldpop.py) needs no changes beyond the per-level
 *      heartbeat additions.
 *
 *      Algorithm:
 *
 *        Step 0  — Idempotent reset. UPDATE rows back to
 *                  population_baseline + zero the audit columns.
 *
 *        Step 1  — Pairwise overlap detection on SIMPLIFIED siblings.
 *                  `ST_Touches` filter drops boundary-only pairs (the
 *                  ones whose ST_Intersection materialises a giant
 *                  line geometry before area filtering). Overlap pop
 *                  computed via population_within_topological.
 *
 *        Step 2  — Triple-overlap sentinel (best-effort, wrapped in
 *                  EXCEPTION block; sets count to -1 on failure
 *                  rather than aborting the whole pass).
 *
 *        Step 3  — TILE-CHUNKED gap detection (L ≥ 2 only).
 *                  Iterate a 4° × 4° grid over the iso's L=1 bbox.
 *                  Per tile:
 *                    - Skip if tile doesn't intersect L=1.
 *                    - parent_tile = simplified L=1 ∩ tile.
 *                    - l_union = ST_Union over SIMPLIFIED L-polys
 *                      that intersect the tile (each clipped to the
 *                      tile to bound geometry size).
 *                    - gap = ST_Difference(parent_tile, l_union),
 *                      dump, filter pieces > 10,000 m² (one WorldPop
 *                      100 m pixel area — sub-pixel slivers from
 *                      simplification mismatch are noise).
 *                    - Per piece: population_within_topological(piece);
 *                      if non-zero, nearest-sibling clamp on
 *                      UN-simplified geom (top-8 by centroid distance
 *                      then re-rank by boundary distance with 100 m tie
 *                      epsilon).
 *
 *   Function signatures unchanged from prior migrations — backward
 *   compatible with Python callers.
 *
 * Cross-iso raster matchups.
 *   Inside `population_within_topological`. Tries `population_within(p_iso, p_geom)`
 *   first; if that returns 0 (the polygon's geometry lies outside its own iso's
 *   raster footprint — synthetic-intermediary case), walks `worldpop_rasters`
 *   for any iso whose raster intersects the input geometry and takes the MAX.
 *   This is the same policy the existing `_topological_raster_fallback` in
 *   Python uses for baseline injection, now consistent across baseline and
 *   correction passes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Cross-iso topological helper ─────────────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_within_topological(
                p_iso  VARCHAR(3),
                p_geom GEOMETRY,
                p_year SMALLINT DEFAULT 2023
            ) RETURNS BIGINT AS $$
            DECLARE
                v_own      BIGINT;
                v_fallback BIGINT;
            BEGIN
                v_own := population_within(p_iso, p_geom, p_year);
                IF v_own IS NOT NULL AND v_own > 0 THEN
                    RETURN v_own;
                END IF;

                SELECT MAX(population_within(r.iso_code::VARCHAR(3), p_geom, p_year))
                  INTO v_fallback
                  FROM worldpop_rasters r
                 WHERE r.iso_code != p_iso
                   AND r.year      = p_year
                   AND ST_Intersects(r.rast, p_geom);

                RETURN COALESCE(v_fallback, 0);
            END;
            $$ LANGUAGE plpgsql STABLE;
        SQL);

        // ── 2. Correction pass v3 ───────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_correction_pass(
                p_iso   TEXT,
                p_level INT,
                p_year  SMALLINT DEFAULT 2023
            ) RETURNS TABLE(
                overlaps_detected       INT,
                overlap_pop_total       BIGINT,
                gap_pieces              INT,
                gap_pop_total           BIGINT,
                triple_overlap_warnings INT
            ) AS $func$
            DECLARE
                -- Tile-grid step in degrees. 4° at the equator ≈ 444 km.
                -- Per-tile ST_Union is bounded by the L-polygons whose
                -- bbox intersects this tile — typically 1–8 polygons even
                -- on continent-scale ISOs.
                v_tile_size CONSTANT DOUBLE PRECISION := 4.0;
                v_tie_epsilon_m CONSTANT DOUBLE PRECISION := 100.0;
                -- Vertex count above which a polygon gets simplified to
                -- 0.001° before any geometric operation. Matches the
                -- threshold inside population_within (lower limit of
                -- "giant" polygon territory). Below this, full fidelity.
                v_simplify_threshold CONSTANT INT := 50000;
                v_simplify_tolerance CONSTANT DOUBLE PRECISION := 0.001;

                v_overlap_count INT    := 0;
                v_overlap_pop   BIGINT := 0;
                v_gap_pieces    INT    := 0;
                v_gap_pop       BIGINT := 0;
                v_triple_warn   INT    := 0;
                v_pair          RECORD;
                v_gap_piece     RECORD;
                v_winner_ids    UUID[];
                v_n_winners     INT;
                v_per_winner    BIGINT;

                v_iso_l1_geom        GEOMETRY;
                v_iso_l1_simp        GEOMETRY;
                v_xmin DOUBLE PRECISION;
                v_ymin DOUBLE PRECISION;
                v_xmax DOUBLE PRECISION;
                v_ymax DOUBLE PRECISION;
                v_tx INT;
                v_ty INT;
                v_tx_min INT;
                v_ty_min INT;
                v_tx_max INT;
                v_ty_max INT;
                v_tile_geom GEOMETRY;
            BEGIN
                -- ── Step 0: idempotent reset to baseline ────────────────
                UPDATE jurisdictions
                   SET population                    = population_baseline,
                       population_overlap_correction = 0,
                       population_gap_correction     = 0
                 WHERE iso_code           = p_iso
                   AND adm_level          = p_level
                   AND deleted_at         IS NULL
                   AND population_baseline IS NOT NULL;

                -- ── Step 1: overlap detection on simplified pairs ───────
                -- ST_Touches filter drops boundary-only adjacency pairs
                -- (whose ST_Intersection materialises a giant line
                -- geometry that bloats memory before the area filter
                -- gets a chance to reject it). Each polygon is simplified
                -- inline at the 50k-vertex / 0.001° threshold.
                FOR v_pair IN
                    WITH simp AS (
                        SELECT id,
                               ST_MakeValid(
                                   CASE WHEN ST_NPoints(geom) > v_simplify_threshold
                                        THEN ST_SimplifyPreserveTopology(geom, v_simplify_tolerance)
                                        ELSE geom
                                   END
                               ) AS sgeom
                          FROM jurisdictions
                         WHERE iso_code   = p_iso
                           AND adm_level  = p_level
                           AND deleted_at IS NULL
                    )
                    SELECT a.id AS id1,
                           b.id AS id2,
                           population_within_topological(
                               p_iso::VARCHAR(3),
                               ST_MakeValid(ST_Intersection(a.sgeom, b.sgeom)),
                               p_year
                           ) AS overlap_pop
                      FROM simp a
                      JOIN simp b
                        ON a.id < b.id
                       AND ST_Intersects(a.sgeom, b.sgeom)
                       AND NOT ST_Touches(a.sgeom, b.sgeom)
                     WHERE ST_Area(ST_Intersection(a.sgeom, b.sgeom)::geography) > 1.0
                LOOP
                    IF v_pair.overlap_pop IS NULL OR v_pair.overlap_pop <= 0 THEN
                        CONTINUE;
                    END IF;

                    v_overlap_count := v_overlap_count + 1;
                    v_overlap_pop   := v_overlap_pop + v_pair.overlap_pop;

                    UPDATE jurisdictions
                       SET population                    = population - (v_pair.overlap_pop / 2),
                           population_overlap_correction = COALESCE(population_overlap_correction, 0)
                                                           - (v_pair.overlap_pop / 2)
                     WHERE id IN (v_pair.id1, v_pair.id2);
                END LOOP;

                -- ── Step 2: triple-overlap sentinel ─────────────────────
                -- Best-effort sanity count. On continental-scale ISOs the
                -- 3-way ST_Intersection can still blow memory even after
                -- simplification, so wrap in EXCEPTION and set -1 on
                -- failure rather than aborting the whole pass.
                BEGIN
                    WITH simp AS (
                        SELECT id,
                               ST_MakeValid(
                                   CASE WHEN ST_NPoints(geom) > v_simplify_threshold
                                        THEN ST_SimplifyPreserveTopology(geom, v_simplify_tolerance)
                                        ELSE geom
                                   END
                               ) AS sgeom
                          FROM jurisdictions
                         WHERE iso_code   = p_iso
                           AND adm_level  = p_level
                           AND deleted_at IS NULL
                    )
                    SELECT COUNT(*)::INT INTO v_triple_warn
                      FROM (
                        SELECT (ST_Dump(
                            ST_Intersection(j1.sgeom,
                                ST_Intersection(j2.sgeom, j3.sgeom))
                        )).geom AS g
                          FROM simp j1
                          JOIN simp j2 ON j1.id < j2.id
                                      AND ST_Intersects(j1.sgeom, j2.sgeom)
                                      AND NOT ST_Touches(j1.sgeom, j2.sgeom)
                          JOIN simp j3 ON j2.id < j3.id
                                      AND ST_Intersects(j1.sgeom, j3.sgeom)
                                      AND ST_Intersects(j2.sgeom, j3.sgeom)
                                      AND NOT ST_Touches(j1.sgeom, j3.sgeom)
                                      AND NOT ST_Touches(j2.sgeom, j3.sgeom)
                      ) triples
                     WHERE NOT ST_IsEmpty(g)
                       AND ST_Area(g::geography) > 1.0;
                EXCEPTION WHEN OTHERS THEN
                    v_triple_warn := -1;
                END;

                -- ── Step 3: TILE-CHUNKED gap detection (L ≥ 2 only) ─────
                -- Skip L = 1: gap = polygon − polygon = ∅.
                IF p_level >= 2 THEN
                    SELECT geom INTO v_iso_l1_geom
                      FROM jurisdictions
                     WHERE iso_code   = p_iso
                       AND adm_level  = 1
                       AND deleted_at IS NULL
                     LIMIT 1;

                    IF v_iso_l1_geom IS NOT NULL THEN
                        -- Simplified copy of L1 used for per-tile parent
                        -- clipping. Same threshold as the inline polys.
                        v_iso_l1_simp := ST_MakeValid(
                            CASE WHEN ST_NPoints(v_iso_l1_geom) > v_simplify_threshold
                                 THEN ST_SimplifyPreserveTopology(v_iso_l1_geom, v_simplify_tolerance)
                                 ELSE v_iso_l1_geom
                            END
                        );

                        SELECT ST_XMin(v_iso_l1_simp), ST_YMin(v_iso_l1_simp),
                               ST_XMax(v_iso_l1_simp), ST_YMax(v_iso_l1_simp)
                          INTO v_xmin, v_ymin, v_xmax, v_ymax;

                        v_tx_min := FLOOR(v_xmin / v_tile_size)::INT;
                        v_ty_min := FLOOR(v_ymin / v_tile_size)::INT;
                        v_tx_max := CEIL (v_xmax / v_tile_size)::INT;
                        v_ty_max := CEIL (v_ymax / v_tile_size)::INT;

                        FOR v_tx IN v_tx_min..v_tx_max LOOP
                            FOR v_ty IN v_ty_min..v_ty_max LOOP
                                v_tile_geom := ST_MakeEnvelope(
                                    v_tx * v_tile_size,
                                    v_ty * v_tile_size,
                                    (v_tx + 1) * v_tile_size,
                                    (v_ty + 1) * v_tile_size,
                                    4326
                                );

                                -- Skip ocean / out-of-range tiles.
                                IF NOT ST_Intersects(v_iso_l1_simp, v_tile_geom) THEN
                                    CONTINUE;
                                END IF;

                                FOR v_gap_piece IN
                                    WITH parent_tile AS (
                                        SELECT ST_MakeValid(
                                            ST_Intersection(v_iso_l1_simp, v_tile_geom)
                                        ) AS geom
                                    ),
                                    l_polys AS (
                                        -- L-polys whose bbox intersects this
                                        -- tile, simplified inline then clipped
                                        -- to the tile. Tile-clipping bounds
                                        -- the per-tile ST_Union work to
                                        -- whatever's actually within the tile
                                        -- rather than each polygon's full extent.
                                        SELECT ST_MakeValid(
                                            ST_Intersection(
                                                ST_MakeValid(
                                                    CASE WHEN ST_NPoints(j.geom) > v_simplify_threshold
                                                         THEN ST_SimplifyPreserveTopology(j.geom, v_simplify_tolerance)
                                                         ELSE j.geom
                                                    END
                                                ),
                                                v_tile_geom
                                            )
                                        ) AS geom
                                          FROM jurisdictions j
                                         WHERE j.iso_code   = p_iso
                                           AND j.adm_level  = p_level
                                           AND j.deleted_at IS NULL
                                           AND ST_Intersects(j.geom, v_tile_geom)
                                    ),
                                    l_union AS (
                                        SELECT ST_Union(geom) AS geom
                                          FROM l_polys
                                         WHERE NOT ST_IsEmpty(geom)
                                    ),
                                    diff AS (
                                        SELECT (ST_Dump(
                                            CASE
                                                WHEN lu.geom IS NULL THEN pt.geom
                                                ELSE ST_Difference(pt.geom, lu.geom)
                                            END
                                        )).geom AS geom
                                          FROM parent_tile pt
                                          LEFT JOIN l_union lu ON TRUE
                                    )
                                    SELECT geom,
                                           population_within_topological(
                                               p_iso::VARCHAR(3), geom, p_year
                                           ) AS gap_pop
                                      FROM diff
                                     WHERE NOT ST_IsEmpty(geom)
                                       -- Floor: 1 WorldPop 100 m pixel = 10,000 m².
                                       -- Below this is sub-pixel boundary jitter from
                                       -- the independent simplification of parent and
                                       -- L-polygons. No real population to attribute.
                                       AND ST_Area(geom::geography) > 10000.0
                                LOOP
                                    IF v_gap_piece.gap_pop IS NULL
                                       OR v_gap_piece.gap_pop <= 0 THEN
                                        CONTINUE;
                                    END IF;

                                    v_gap_pieces := v_gap_pieces + 1;
                                    v_gap_pop    := v_gap_pop + v_gap_piece.gap_pop;

                                    -- Nearest-sibling clamp uses the UN-simplified
                                    -- jurisdictions.geom so a coastal sliver gets
                                    -- attributed to the polygon whose true
                                    -- boundary is closest, not whose simplified
                                    -- outline happens to skim closer. Top-8 by
                                    -- centroid distance (GIST KNN), re-rank by
                                    -- boundary-to-boundary distance with 100 m
                                    -- tie epsilon.
                                    WITH candidates AS (
                                        SELECT id, geom
                                          FROM jurisdictions
                                         WHERE iso_code   = p_iso
                                           AND adm_level  = p_level
                                           AND deleted_at IS NULL
                                         ORDER BY ST_Centroid(geom) <-> ST_Centroid(v_gap_piece.geom)
                                         LIMIT 8
                                    ),
                                    ranked AS (
                                        SELECT id,
                                               ST_Distance(
                                                   ST_Boundary(geom)::geography,
                                                   ST_Boundary(v_gap_piece.geom)::geography
                                               ) AS bd
                                          FROM candidates
                                    )
                                    SELECT ARRAY_AGG(id) INTO v_winner_ids
                                      FROM ranked
                                     WHERE bd - (SELECT MIN(bd) FROM ranked) < v_tie_epsilon_m;

                                    v_n_winners := COALESCE(array_length(v_winner_ids, 1), 0);
                                    IF v_n_winners = 0 THEN
                                        CONTINUE;
                                    END IF;

                                    v_per_winner := v_gap_piece.gap_pop / v_n_winners;

                                    UPDATE jurisdictions
                                       SET population                = population + v_per_winner,
                                           population_gap_correction = COALESCE(population_gap_correction, 0)
                                                                       + v_per_winner
                                     WHERE id = ANY(v_winner_ids);
                                END LOOP;
                            END LOOP;
                        END LOOP;
                    END IF;
                END IF;

                RETURN QUERY SELECT
                    v_overlap_count,
                    v_overlap_pop,
                    v_gap_pieces,
                    v_gap_pop,
                    v_triple_warn;
            END;
            $func$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS population_within_topological(VARCHAR, GEOMETRY, SMALLINT)');
        // population_correction_pass: T.5 migration restores its own
        // CREATE OR REPLACE on rollback.
    }
};
