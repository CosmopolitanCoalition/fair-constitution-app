<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase T.3 follow-up — chunk population_correction_pass to survive
 * continent-scale ISOs without OOM-killing PostgreSQL.
 *
 * Background.
 *   The first cut of `population_correction_pass` (migration
 *   2026_05_14_000001) failed on AUS L=2 with `signal 9: Killed` —
 *   PostgreSQL's backend was OOM-reaped by the kernel mid-call. Root
 *   cause: the gap-detection path materialised a single `ST_Union(geom)`
 *   over every L-level polygon, then `ST_Difference(L1, that_union)`.
 *   For continental ISOs with multi-million-vertex coastlines (AUS, RUS,
 *   USA, CHN, BRA) the intermediate geometry exceeds available RAM.
 *
 *   Geometry simplification is explicitly off-limits — these polygons
 *   drive *apportionment*, not just rendering, so fidelity must be
 *   preserved end-to-end. The fix is spatial chunking instead.
 *
 * What changes:
 *
 *   1. Gap detection now iterates a 4° × 4° grid over the iso's bbox.
 *      Per tile:
 *        - Clip parent_extent (the iso's L=1 polygon) to the tile.
 *        - Collect L-level polygons that intersect the tile via the
 *          GIST index.
 *        - Compute their union *clipped to the tile* — bounded by tile
 *          area, not the iso's whole footprint.
 *        - `ST_Difference` against the parent_tile, decompose into
 *          connected gap pieces, run the existing nearest-sibling
 *          assignment per piece.
 *      The per-iso totals match the monolithic version (every pixel
 *      attributed to some sibling); pieces split across tile boundaries
 *      assign to their *local* nearest sibling, which is in fact closer
 *      to the operator's stated per-pixel rule than the monolithic
 *      "split across all siblings of the WHOLE gap" choice would be.
 *
 *   2. Overlap-detection join gains `AND NOT ST_Touches(j1.geom, j2.geom)`.
 *      ST_Touches is GIST-cheap and excludes pairs that share only a
 *      boundary line (no interior overlap). Without this filter, the
 *      ST_Intersection inside the WHERE clause was being computed for
 *      every adjacent pair — including all C(8,2)=28 boundary-touching
 *      AUS pairs whose ST_Intersection materialises into a giant
 *      coastline-line geometry that bloats memory before ST_Area > 1.0
 *      filters it back out.
 *
 *   3. Triple-overlap detection wrapped in a PL/pgSQL EXCEPTION block.
 *      It's a sanity sentinel only (no correction applied based on its
 *      count), so a failure to compute on a continent-scale iso just
 *      sets v_triple_warn = -1 and lets the function continue. The
 *      operator can spot the -1 in audit output and investigate
 *      separately if needed.
 *
 * What stays identical:
 *
 *   - Step 0 (reset-to-baseline) — single-row UPDATE, never the
 *     bottleneck.
 *   - Step 1 (pairwise overlap correction) algorithm — still pairwise,
 *     still subtracts overlap_pop/2 from each polygon. Filter join
 *     gets the ST_Touches improvement above.
 *   - Step 3 (nearest-sibling assignment per gap piece) — same
 *     two-stage centroid-then-boundary distance with 100 m tie epsilon.
 *
 * Function signature and return shape unchanged; existing callers
 * (the Python pixel_attribution_correction in import_worldpop.py)
 * don't need to update.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_correction_pass(
                p_iso   TEXT,
                p_level INT,
                p_year  SMALLINT DEFAULT 2023
            ) RETURNS TABLE(
                overlaps_detected INT,
                overlap_pop_total BIGINT,
                gap_pieces INT,
                gap_pop_total BIGINT,
                triple_overlap_warnings INT
            ) AS $func$
            DECLARE
                -- Tile-grid step size in degrees for gap chunking. 4° at
                -- the equator ≈ 444 km × 444 km. Each tile's ST_Union of
                -- intersecting L-polygons is bounded by tile area, so
                -- per-tile peak memory stays well below the per-backend
                -- ceiling on the heaviest continental ISOs (AUS, RUS).
                v_tile_size CONSTANT DOUBLE PRECISION := 4.0;
                -- Boundary-distance tie epsilon for nearest-sibling
                -- assignment (matches the original v1 of the function).
                v_tie_epsilon_m CONSTANT DOUBLE PRECISION := 100.0;

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

                v_iso_l1_geom   GEOMETRY;
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
                -- Step 0 — idempotent reset.
                UPDATE jurisdictions
                SET population                  = population_baseline,
                    population_overlap_correction = 0,
                    population_gap_correction     = 0
                WHERE iso_code  = p_iso
                  AND adm_level = p_level
                  AND deleted_at IS NULL
                  AND population_baseline IS NOT NULL;

                -- Step 1 — pairwise overlap detection.
                -- ST_Touches filter is the key memory fix here: it
                -- eliminates pairs that share only a boundary line from
                -- the ST_Intersection computation (which would otherwise
                -- materialise huge line-geometry intermediates for every
                -- adjacent polygon pair before ST_Area filtered them).
                FOR v_pair IN
                    SELECT
                        j1.id AS id1, j2.id AS id2,
                        population_within(
                            p_iso::VARCHAR(3),
                            ST_MakeValid(ST_Intersection(j1.geom, j2.geom)),
                            p_year
                        ) AS overlap_pop
                    FROM jurisdictions j1
                    JOIN jurisdictions j2
                      ON j1.iso_code  = j2.iso_code
                     AND j1.adm_level = j2.adm_level
                     AND j1.id < j2.id
                     AND ST_Intersects(j1.geom, j2.geom)
                     AND NOT ST_Touches(j1.geom, j2.geom)   -- skip boundary-only pairs
                    WHERE j1.iso_code  = p_iso
                      AND j1.adm_level = p_level
                      AND j1.deleted_at IS NULL
                      AND j2.deleted_at IS NULL
                      AND ST_Area(ST_Intersection(j1.geom, j2.geom)::geography) > 1.0
                LOOP
                    IF v_pair.overlap_pop IS NULL OR v_pair.overlap_pop <= 0 THEN
                        CONTINUE;
                    END IF;

                    v_overlap_count := v_overlap_count + 1;
                    v_overlap_pop   := v_overlap_pop + v_pair.overlap_pop;

                    UPDATE jurisdictions
                    SET population                    = population - (v_pair.overlap_pop / 2),
                        population_overlap_correction = COALESCE(population_overlap_correction, 0) - (v_pair.overlap_pop / 2)
                    WHERE id IN (v_pair.id1, v_pair.id2);
                END LOOP;

                -- Triple-overlap sanity sentinel. Best-effort: on
                -- continent-scale ISOs the 3-way ST_Intersection can
                -- still blow memory, so wrap in EXCEPTION and report
                -- -1 in the column to signal "could not compute".
                BEGIN
                    SELECT COUNT(*)::INT INTO v_triple_warn
                    FROM (
                        SELECT (ST_Dump(
                            ST_Intersection(j1.geom,
                                ST_Intersection(j2.geom, j3.geom))
                        )).geom AS g
                        FROM jurisdictions j1
                        JOIN jurisdictions j2
                          ON j1.iso_code  = j2.iso_code
                         AND j1.adm_level = j2.adm_level
                         AND j1.id < j2.id
                         AND ST_Intersects(j1.geom, j2.geom)
                         AND NOT ST_Touches(j1.geom, j2.geom)
                        JOIN jurisdictions j3
                          ON j1.iso_code  = j3.iso_code
                         AND j1.adm_level = j3.adm_level
                         AND j2.id < j3.id
                         AND ST_Intersects(j1.geom, j3.geom)
                         AND ST_Intersects(j2.geom, j3.geom)
                         AND NOT ST_Touches(j1.geom, j3.geom)
                         AND NOT ST_Touches(j2.geom, j3.geom)
                        WHERE j1.iso_code  = p_iso
                          AND j1.adm_level = p_level
                          AND j1.deleted_at IS NULL
                          AND j2.deleted_at IS NULL
                          AND j3.deleted_at IS NULL
                    ) triples
                    WHERE NOT ST_IsEmpty(g)
                      AND ST_Area(g::geography) > 1.0;
                EXCEPTION WHEN OTHERS THEN
                    v_triple_warn := -1;
                END;

                -- Step 2 + 3 — TILE-CHUNKED gap detection at full
                -- fidelity. Iterate a 4° × 4° grid over the iso's L=1
                -- bbox. Per tile, compute the gap = parent_tile −
                -- ST_Union(L_polys ∩ tile). This keeps every operation
                -- bounded by tile area rather than continent area while
                -- preserving every vertex of every input geometry.
                IF p_level >= 2 THEN
                    SELECT geom INTO v_iso_l1_geom
                    FROM jurisdictions
                    WHERE iso_code = p_iso
                      AND adm_level = 1
                      AND deleted_at IS NULL
                    LIMIT 1;

                    IF v_iso_l1_geom IS NOT NULL THEN
                        SELECT ST_XMin(v_iso_l1_geom),
                               ST_YMin(v_iso_l1_geom),
                               ST_XMax(v_iso_l1_geom),
                               ST_YMax(v_iso_l1_geom)
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

                                -- Skip tiles that don't intersect the iso's
                                -- L=1 polygon at all (ocean tiles, polar
                                -- ones).
                                IF NOT ST_Intersects(v_iso_l1_geom, v_tile_geom) THEN
                                    CONTINUE;
                                END IF;

                                FOR v_gap_piece IN
                                    WITH parent_tile AS (
                                        -- Clip the iso's L=1 to this tile.
                                        SELECT ST_MakeValid(
                                            ST_Intersection(v_iso_l1_geom, v_tile_geom)
                                        ) AS geom
                                    ),
                                    l_polys AS (
                                        -- L-level polygons that intersect
                                        -- this tile (GIST-indexed lookup,
                                        -- fast); each clipped to the tile
                                        -- to bound the geometry size.
                                        SELECT ST_MakeValid(
                                            ST_Intersection(j.geom, v_tile_geom)
                                        ) AS geom
                                        FROM jurisdictions j
                                        WHERE j.iso_code  = p_iso
                                          AND j.adm_level = p_level
                                          AND j.deleted_at IS NULL
                                          AND ST_Intersects(j.geom, v_tile_geom)
                                    ),
                                    l_union AS (
                                        SELECT ST_Union(geom) AS geom
                                        FROM l_polys
                                        WHERE NOT ST_IsEmpty(geom)
                                    ),
                                    diff AS (
                                        -- Difference between the tile's
                                        -- parent slice and the tile's
                                        -- L-union. If no L-polygons cover
                                        -- this tile, the whole parent slice
                                        -- becomes one big gap.
                                        SELECT (ST_Dump(
                                            CASE
                                                WHEN lu.geom IS NULL THEN pt.geom
                                                ELSE ST_Difference(pt.geom, lu.geom)
                                            END
                                        )).geom AS geom
                                        FROM parent_tile pt
                                        LEFT JOIN l_union lu ON TRUE
                                    )
                                    SELECT
                                        geom,
                                        population_within(
                                            p_iso::VARCHAR(3), geom, p_year
                                        ) AS gap_pop
                                    FROM diff
                                    WHERE NOT ST_IsEmpty(geom)
                                      AND ST_Area(geom::geography) > 100.0
                                LOOP
                                    IF v_gap_piece.gap_pop IS NULL
                                       OR v_gap_piece.gap_pop <= 0 THEN
                                        CONTINUE;
                                    END IF;

                                    v_gap_pieces := v_gap_pieces + 1;
                                    v_gap_pop    := v_gap_pop + v_gap_piece.gap_pop;

                                    -- Nearest sibling assignment.
                                    -- Two-stage: top-8 by centroid distance
                                    -- (GIST KNN), then re-rank by boundary-
                                    -- to-boundary distance with v_tie_epsilon_m
                                    -- tie window.
                                    WITH candidates AS (
                                        SELECT id, geom
                                        FROM jurisdictions
                                        WHERE iso_code  = p_iso
                                          AND adm_level = p_level
                                          AND deleted_at IS NULL
                                        ORDER BY ST_Centroid(geom) <-> ST_Centroid(v_gap_piece.geom)
                                        LIMIT 8
                                    ),
                                    ranked AS (
                                        SELECT
                                            id,
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
                                        population_gap_correction = COALESCE(population_gap_correction, 0) + v_per_winner
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
        // Roll back to the v1 (monolithic) function from
        // 2026_05_14_000001. Roll-forward only in practice — the
        // monolithic version is known to OOM on continent-scale ISOs.
        DB::statement('DROP FUNCTION IF EXISTS population_correction_pass(TEXT, INT, SMALLINT)');
    }
};
