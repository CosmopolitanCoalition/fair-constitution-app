<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase T.8 — Chunkable correction SQL helpers.
 *
 * Why this exists.
 *   The v3 monolithic `population_correction_pass(iso, level)` runs an
 *   entire (iso, level) in one PostgreSQL backend, one transaction. From
 *   Python's view it's opaque — no progress visible until it returns.
 *   On CAN L=2 that opaque window was 27+ minutes with no way to confirm
 *   forward motion. The operator's NEW PLAN: "MOST CHUNKABLE WAY POSSIBLE
 *   THAT IS TRACKABLE".
 *
 *   The work has natural chunk boundaries:
 *     - Per row for reset-to-baseline (already a plain UPDATE; Python
 *       drives the batching, no SQL helper needed).
 *     - Per pair for overlap detection.
 *     - Per tile for gap detection.
 *     - Per piece for gap-piece nearest-sibling assignment.
 *     - Per tile for cross-iso orphan attribution (Step 5).
 *     - Per piece for cross-iso orphan cascade (Step 5).
 *
 *   This migration ships seven SQL helpers, each scoped to one chunk.
 *   Python orchestrates the chunks, commits per chunk, ticks heartbeat
 *   per chunk. Per-chunk SQL cost is bounded (single pair, single tile,
 *   single piece) so no chunk can exceed a few seconds.
 *
 *   The existing v3 `population_correction_pass(iso, level, year)` is
 *   NOT replaced — it stays as a one-shot wrapper for callers that
 *   don't need per-chunk heartbeat. The new Python orchestrator calls
 *   the chunked helpers directly.
 *
 * Helpers shipped:
 *
 *   STEPS 1–3 (within-iso correction):
 *
 *   1. `population_correction_overlap_candidates(iso, level)` →
 *      TABLE(id1 uuid, id2 uuid)
 *      Returns the set of overlap-candidate pairs after the simplified
 *      ST_Intersects + ST_Touches + area>1m² filter. One SQL call per
 *      (iso, level). Python iterates the result, calls
 *      `population_correction_apply_overlap` per pair.
 *
 *   2. `population_correction_apply_overlap(id1, id2, year)` →
 *      TABLE(overlap_pop bigint, applied boolean)
 *      For one pair: simplify, compute intersection, get
 *      population_within_topological, apply -overlap_pop/2 to both
 *      polygons + audit column. Returns the applied pop. Idempotent
 *      reset already happened earlier in the pass; this helper just
 *      adds the correction delta.
 *
 *   3. `population_correction_gap_tile(iso, level, tx, ty, tile_size,
 *      year)` → TABLE(piece_geom geometry, piece_pop bigint,
 *      area_m2 double precision)
 *      For one 4°×4° tile: clip simplified L=1 to tile, union
 *      simplified L-polys in tile, ST_Difference, ST_Dump, return
 *      pieces > 10,000 m² with non-zero pop via
 *      population_within_topological. Returns empty if tile doesn't
 *      intersect L=1.
 *
 *   4. `population_correction_apply_gap_piece(iso, level, piece_geom,
 *      gap_pop)` → TABLE(winner_ids uuid[], n_winners int,
 *      per_winner bigint)
 *      For one piece: top-8 nearest centroids + boundary-distance
 *      re-rank with 100m tie epsilon (UN-simplified geom). Applies
 *      +per_winner to each winner's population + population_gap_correction.
 *
 *   STEP 5 (cross-iso orphan attribution):
 *
 *   5. `population_cross_iso_orphan_tile(tx, ty, tile_size, year)` →
 *      TABLE(piece_geom geometry, piece_pop bigint, nearest_iso text)
 *      For one global tile: enumerate pixels NOT in any iso's L=1,
 *      decompose into pieces, return each piece > 10,000 m² with
 *      non-zero pop and the nearest iso's L=1 (by boundary distance).
 *
 *   6. `population_cross_iso_apply_orphan_piece(piece_geom, piece_pop,
 *      owning_iso)` → TABLE(rows_updated int, cascaded_levels int[])
 *      For one orphan piece: cascade through the owning iso's levels
 *      (L=1, then nearest L=2 sibling of owning iso, then nearest
 *      L=3 sibling of owning iso, …) adding piece_pop to each.
 *      Preserves the per-iso invariant SUM(L_n) = L=1 at every level
 *      the iso exposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1 helper: overlap candidate pair enumerator ────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_correction_overlap_candidates(
                p_iso   TEXT,
                p_level INT
            ) RETURNS TABLE(id1 UUID, id2 UUID) AS $func$
            BEGIN
                -- Phase T.8.1: use the caller-prepared session temp
                -- table `_simp_l_polys` (id uuid, sgeom geometry) when
                -- present. This avoids re-running the expensive
                -- ST_SimplifyPreserveTopology inline on every call —
                -- on CAN L=2 the inline path took 60+ s just simplifying
                -- the 13 polygons (Nunavut alone is 5.4 M points).
                IF to_regclass('_simp_l_polys') IS NOT NULL THEN
                    RETURN QUERY
                    SELECT a.id AS id1, b.id AS id2
                      FROM _simp_l_polys a
                      JOIN _simp_l_polys b
                        ON a.id < b.id
                       AND ST_Intersects(a.sgeom, b.sgeom)
                       AND NOT ST_Touches(a.sgeom, b.sgeom)
                     WHERE ST_Area(ST_Intersection(a.sgeom, b.sgeom)::geography) > 1.0;
                ELSE
                    -- Backward compat: inline simplify on every call.
                    RETURN QUERY
                    WITH simp AS (
                        SELECT id,
                               ST_MakeValid(
                                   CASE WHEN ST_NPoints(geom) > 50000
                                        THEN ST_SimplifyPreserveTopology(geom, 0.001)
                                        ELSE geom
                                   END
                               ) AS sgeom
                          FROM jurisdictions
                         WHERE iso_code   = p_iso
                           AND adm_level  = p_level
                           AND deleted_at IS NULL
                    )
                    SELECT a.id AS id1, b.id AS id2
                      FROM simp a
                      JOIN simp b
                        ON a.id < b.id
                       AND ST_Intersects(a.sgeom, b.sgeom)
                       AND NOT ST_Touches(a.sgeom, b.sgeom)
                     WHERE ST_Area(ST_Intersection(a.sgeom, b.sgeom)::geography) > 1.0;
                END IF;
            END;
            $func$ LANGUAGE plpgsql STABLE;
        SQL);

        // ── Step 1 helper: apply one overlap pair ───────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_correction_apply_overlap(
                p_id1  UUID,
                p_id2  UUID,
                p_year SMALLINT DEFAULT 2023
            ) RETURNS TABLE(overlap_pop BIGINT, applied BOOLEAN) AS $func$
            DECLARE
                v_iso        VARCHAR(3);
                v_g1         GEOMETRY;
                v_g2         GEOMETRY;
                v_intersect  GEOMETRY;
                v_overlap_pop BIGINT;
            BEGIN
                -- iso always read from jurisdictions (small).
                SELECT iso_code INTO v_iso
                  FROM jurisdictions WHERE id = p_id1 AND deleted_at IS NULL;
                IF NOT FOUND THEN
                    RETURN QUERY SELECT 0::BIGINT, FALSE;
                    RETURN;
                END IF;

                -- Phase T.8.1: prefer pre-simplified geoms from the
                -- caller-prepared `_simp_l_polys` temp table. On giants
                -- (CAN L=2 with Nunavut at 5.4 M points) this skips
                -- ~24 s of ST_SimplifyPreserveTopology per polygon per
                -- call.
                IF to_regclass('_simp_l_polys') IS NOT NULL THEN
                    SELECT sgeom INTO v_g1 FROM _simp_l_polys WHERE id = p_id1;
                    SELECT sgeom INTO v_g2 FROM _simp_l_polys WHERE id = p_id2;
                ELSE
                    SELECT geom INTO v_g1 FROM jurisdictions WHERE id = p_id1 AND deleted_at IS NULL;
                    SELECT geom INTO v_g2 FROM jurisdictions WHERE id = p_id2 AND deleted_at IS NULL;
                END IF;
                IF v_g1 IS NULL OR v_g2 IS NULL THEN
                    RETURN QUERY SELECT 0::BIGINT, FALSE;
                    RETURN;
                END IF;

                -- Inline simplify only if we didn't get pre-simplified
                -- geom from the temp table (backward-compat fallback).
                IF to_regclass('_simp_l_polys') IS NULL THEN
                    v_g1 := ST_MakeValid(
                        CASE WHEN ST_NPoints(v_g1) > 50000
                             THEN ST_SimplifyPreserveTopology(v_g1, 0.001)
                             ELSE v_g1
                        END
                    );
                    v_g2 := ST_MakeValid(
                        CASE WHEN ST_NPoints(v_g2) > 50000
                             THEN ST_SimplifyPreserveTopology(v_g2, 0.001)
                             ELSE v_g2
                        END
                    );
                END IF;

                v_intersect := ST_MakeValid(ST_Intersection(v_g1, v_g2));
                IF ST_IsEmpty(v_intersect) OR ST_Touches(v_g1, v_g2) THEN
                    RETURN QUERY SELECT 0::BIGINT, FALSE;
                    RETURN;
                END IF;

                v_overlap_pop := population_within_topological(v_iso, v_intersect, p_year);
                IF v_overlap_pop IS NULL OR v_overlap_pop <= 0 THEN
                    RETURN QUERY SELECT 0::BIGINT, FALSE;
                    RETURN;
                END IF;

                UPDATE jurisdictions
                   SET population                    = population - (v_overlap_pop / 2),
                       population_overlap_correction = COALESCE(population_overlap_correction, 0)
                                                       - (v_overlap_pop / 2)
                 WHERE id IN (p_id1, p_id2);

                RETURN QUERY SELECT v_overlap_pop, TRUE;
            END;
            $func$ LANGUAGE plpgsql;
        SQL);

        // ── Step 3 helper: find gap pieces in one tile ──────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_correction_gap_tile(
                p_iso          TEXT,
                p_level        INT,
                p_tx           INT,
                p_ty           INT,
                p_tile_size    DOUBLE PRECISION DEFAULT 4.0,
                p_year         SMALLINT DEFAULT 2023,
                p_l1_simp_geom GEOMETRY DEFAULT NULL
            ) RETURNS TABLE(
                piece_geom GEOMETRY,
                piece_pop  BIGINT,
                area_m2    DOUBLE PRECISION
            ) AS $func$
            DECLARE
                v_tile_geom        GEOMETRY;
                v_iso_l1_simp      GEOMETRY;
                v_iso_l1_raw       GEOMETRY;
                v_parent_tile_geom GEOMETRY;
                v_parent_pop       BIGINT;
                v_l_union_geom     GEOMETRY;
                v_claimed_pop      BIGINT;
            BEGIN
                v_tile_geom := ST_MakeEnvelope(
                    p_tx * p_tile_size,
                    p_ty * p_tile_size,
                    (p_tx + 1) * p_tile_size,
                    (p_ty + 1) * p_tile_size,
                    4326
                );

                -- Phase T.8.1 hoisted simplification: if caller supplied
                -- a pre-simplified L=1, use it directly (saves ~24 s of
                -- ST_SimplifyPreserveTopology per call on continent-scale
                -- ISOs). Otherwise compute locally for backward compat.
                IF p_l1_simp_geom IS NOT NULL THEN
                    v_iso_l1_simp := p_l1_simp_geom;
                ELSE
                    SELECT geom INTO v_iso_l1_raw
                      FROM jurisdictions
                     WHERE iso_code   = p_iso
                       AND adm_level  = 1
                       AND deleted_at IS NULL
                     LIMIT 1;
                    IF v_iso_l1_raw IS NULL THEN
                        RETURN;
                    END IF;
                    v_iso_l1_simp := ST_MakeValid(
                        CASE WHEN ST_NPoints(v_iso_l1_raw) > 50000
                             THEN ST_SimplifyPreserveTopology(v_iso_l1_raw, 0.001)
                             ELSE v_iso_l1_raw
                        END
                    );
                END IF;

                IF NOT ST_Intersects(v_iso_l1_simp, v_tile_geom) THEN
                    RETURN;
                END IF;

                -- ── Phase T.8.1 precheck 1 — zero parent population ───
                -- The cheapest skip: if there are no populated raster
                -- pixels inside L=1 ∩ tile, no orphan piece can exist
                -- there (any gap would be all-zero pixels). Skip the
                -- whole vector pipeline below.
                v_parent_tile_geom := ST_MakeValid(
                    ST_Intersection(v_iso_l1_simp, v_tile_geom)
                );
                v_parent_pop := population_within_topological(
                    p_iso::VARCHAR(3), v_parent_tile_geom, p_year
                );
                IF COALESCE(v_parent_pop, 0) = 0 THEN
                    RETURN;
                END IF;

                -- Compute the simplified L-poly union inside this tile.
                -- Phase T.8.1: prefer pre-simplified geoms from the
                -- caller-prepared `_simp_l_polys` temp table to avoid
                -- re-running ST_SimplifyPreserveTopology per tile.
                IF to_regclass('_simp_l_polys') IS NOT NULL THEN
                    SELECT ST_Union(ST_MakeValid(ST_Intersection(sgeom, v_tile_geom)))
                      INTO v_l_union_geom
                      FROM _simp_l_polys
                     WHERE ST_Intersects(sgeom, v_tile_geom);
                ELSE
                    SELECT ST_Union(geom) INTO v_l_union_geom
                      FROM (
                        SELECT ST_MakeValid(
                            ST_Intersection(
                                ST_MakeValid(
                                    CASE WHEN ST_NPoints(j.geom) > 50000
                                         THEN ST_SimplifyPreserveTopology(j.geom, 0.001)
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
                      ) lp
                     WHERE NOT ST_IsEmpty(geom);
                END IF;

                -- ── Phase T.8.1 precheck 2 — full L-poly claim ────────
                -- If the L-polys' union covers every populated pixel in
                -- the parent (claimed_pop ≥ parent_pop), every parent
                -- pixel is claimed by some L-poly. No orphan can exist.
                -- Skip the ST_Difference + ST_Dump + per-piece work.
                IF v_l_union_geom IS NOT NULL THEN
                    v_claimed_pop := COALESCE(
                        population_within_topological(
                            p_iso::VARCHAR(3), v_l_union_geom, p_year
                        ),
                        0
                    );
                    IF v_claimed_pop >= v_parent_pop THEN
                        RETURN;
                    END IF;
                END IF;

                -- Neither precheck short-circuited — there's real gap
                -- mass in this tile. Run the existing ST_Difference +
                -- per-piece pipeline.
                RETURN QUERY
                WITH diff AS (
                    SELECT (ST_Dump(
                        CASE
                            WHEN v_l_union_geom IS NULL
                                THEN v_parent_tile_geom
                            ELSE ST_Difference(v_parent_tile_geom, v_l_union_geom)
                        END
                    )).geom AS geom
                )
                SELECT
                    d.geom AS piece_geom,
                    population_within_topological(
                        p_iso::VARCHAR(3), d.geom, p_year
                    ) AS piece_pop,
                    ST_Area(d.geom::geography) AS area_m2
                  FROM diff d
                 WHERE NOT ST_IsEmpty(d.geom)
                   AND ST_Area(d.geom::geography) > 10000.0;
            END;
            $func$ LANGUAGE plpgsql;
        SQL);

        // ── Step 3 helper: apply one gap piece's nearest-sibling clamp ──
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_correction_apply_gap_piece(
                p_iso        TEXT,
                p_level      INT,
                p_piece_geom GEOMETRY,
                p_gap_pop    BIGINT
            ) RETURNS TABLE(
                winner_ids  UUID[],
                n_winners   INT,
                per_winner  BIGINT
            ) AS $func$
            DECLARE
                v_tie_epsilon_m CONSTANT DOUBLE PRECISION := 100.0;
                v_winner_ids    UUID[];
                v_n_winners     INT;
                v_per_winner    BIGINT;
            BEGIN
                IF p_gap_pop IS NULL OR p_gap_pop <= 0 THEN
                    RETURN QUERY SELECT NULL::UUID[], 0, 0::BIGINT;
                    RETURN;
                END IF;

                -- Nearest-sibling: top-8 by centroid GIST KNN, re-rank by
                -- boundary-to-boundary distance with 100m tie epsilon.
                -- Uses UN-simplified jurisdictions.geom — tie decisions
                -- always at full fidelity.
                WITH candidates AS (
                    SELECT id, geom
                      FROM jurisdictions
                     WHERE iso_code   = p_iso
                       AND adm_level  = p_level
                       AND deleted_at IS NULL
                     ORDER BY ST_Centroid(geom) <-> ST_Centroid(p_piece_geom)
                     LIMIT 8
                ),
                ranked AS (
                    SELECT id,
                           ST_Distance(
                               ST_Boundary(geom)::geography,
                               ST_Boundary(p_piece_geom)::geography
                           ) AS bd
                      FROM candidates
                )
                SELECT ARRAY_AGG(id) INTO v_winner_ids
                  FROM ranked
                 WHERE bd - (SELECT MIN(bd) FROM ranked) < v_tie_epsilon_m;

                v_n_winners := COALESCE(array_length(v_winner_ids, 1), 0);
                IF v_n_winners = 0 THEN
                    RETURN QUERY SELECT NULL::UUID[], 0, 0::BIGINT;
                    RETURN;
                END IF;

                v_per_winner := p_gap_pop / v_n_winners;

                UPDATE jurisdictions
                   SET population                = population + v_per_winner,
                       population_gap_correction = COALESCE(population_gap_correction, 0)
                                                   + v_per_winner
                 WHERE id = ANY(v_winner_ids);

                RETURN QUERY SELECT v_winner_ids, v_n_winners, v_per_winner;
            END;
            $func$ LANGUAGE plpgsql;
        SQL);

        // ── Step 5 helper: find cross-iso orphan pieces in one tile ─────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_cross_iso_orphan_tile(
                p_tx        INT,
                p_ty        INT,
                p_tile_size DOUBLE PRECISION DEFAULT 4.0,
                p_year      SMALLINT DEFAULT 2023
            ) RETURNS TABLE(
                piece_geom   GEOMETRY,
                piece_pop    BIGINT,
                nearest_iso  TEXT
            ) AS $func$
            DECLARE
                v_tile_geom    GEOMETRY;
                v_has_rast     BOOLEAN;
                v_total_pop    BIGINT;
                v_l1_union     GEOMETRY;
                v_claimed_pop  BIGINT;
            BEGIN
                v_tile_geom := ST_MakeEnvelope(
                    p_tx * p_tile_size,
                    p_ty * p_tile_size,
                    (p_tx + 1) * p_tile_size,
                    (p_ty + 1) * p_tile_size,
                    4326
                );

                -- Skip ocean / empty tiles where no raster intersects.
                SELECT EXISTS(
                    SELECT 1 FROM worldpop_rasters r
                     WHERE r.year = p_year AND ST_Intersects(r.rast, v_tile_geom)
                ) INTO v_has_rast;
                IF NOT v_has_rast THEN
                    RETURN;
                END IF;

                -- ── Phase T.8.1 precheck 1 — zero raster population ───
                -- Sum pop across every raster touching this tile, bounded
                -- to the tile envelope. If 0, no cross-iso orphan can
                -- possibly exist in this tile.
                SELECT COALESCE(
                    SUM(population_within(r.iso_code::VARCHAR(3), v_tile_geom, p_year)),
                    0
                )::BIGINT INTO v_total_pop
                  FROM worldpop_rasters r
                 WHERE r.year = p_year
                   AND ST_Intersects(r.rast, v_tile_geom);
                IF COALESCE(v_total_pop, 0) = 0 THEN
                    RETURN;
                END IF;

                -- Per-tile ST_Union over the simplified L=1 polygons that
                -- actually intersect this tile. The tile envelope bounds
                -- this to ~3-15 polygons even on border tiles. NOTE: the
                -- hoist attempt (one global union upfront) was reverted
                -- because ST_Union over all 232 L=1s is O(n²) and
                -- intrinsically memory-hungry (15+ min, 85 % container
                -- memory). Keep this per-tile structure — small chunks.
                SELECT ST_Union(
                    ST_MakeValid(
                        CASE WHEN ST_NPoints(geom) > 50000
                             THEN ST_SimplifyPreserveTopology(geom, 0.001)
                             ELSE geom
                        END
                    )
                ) INTO v_l1_union
                  FROM jurisdictions
                 WHERE adm_level  = 1
                   AND deleted_at IS NULL
                   AND iso_code   IS NOT NULL
                   AND ST_Intersects(geom, v_tile_geom);

                -- ── Phase T.8.1 precheck 2 — full L=1 claim ───────────
                -- If every populated pixel in the tile sits inside the
                -- L=1 union (claimed_pop ≥ total_pop), no cross-iso
                -- orphan exists. Skip the rest of the pipeline.
                IF v_l1_union IS NOT NULL THEN
                    SELECT COALESCE(
                        SUM(population_within(r.iso_code::VARCHAR(3),
                                              ST_Intersection(v_l1_union, v_tile_geom),
                                              p_year)),
                        0
                    )::BIGINT INTO v_claimed_pop
                      FROM worldpop_rasters r
                     WHERE r.year = p_year
                       AND ST_Intersects(r.rast, v_tile_geom);
                    IF v_claimed_pop >= v_total_pop THEN
                        RETURN;
                    END IF;
                END IF;

                -- Both prechecks passed: this tile has populated pixels
                -- that aren't all claimed by some iso's L=1. Reuse the
                -- v_l1_union computed during precheck — no second
                -- ST_Union pass needed.
                RETURN QUERY
                WITH diff AS (
                    SELECT (ST_Dump(
                        ST_Difference(
                            v_tile_geom,
                            COALESCE(v_l1_union, ST_GeomFromText('POLYGON EMPTY', 4326))
                        )
                    )).geom AS geom
                ),
                pieces AS (
                    SELECT d.geom,
                           ST_Area(d.geom::geography) AS area_m2
                      FROM diff d
                     WHERE NOT ST_IsEmpty(d.geom)
                       AND ST_Area(d.geom::geography) > 10000.0
                )
                SELECT
                    p.geom AS piece_geom,
                    -- For an orphan piece, the population is the raster's
                    -- value inside the piece. Sum across ANY iso's raster
                    -- whose footprint intersects the piece.
                    COALESCE((
                        SELECT SUM(population_within(r.iso_code::VARCHAR(3), p.geom, p_year))
                          FROM worldpop_rasters r
                         WHERE r.year = p_year
                           AND ST_Intersects(r.rast, p.geom)
                    ), 0)::BIGINT AS piece_pop,
                    -- The iso whose L=1 is closest to this piece (by
                    -- boundary distance), with top-8 GIST KNN pre-filter.
                    (
                        WITH candidates AS (
                            SELECT iso_code, geom
                              FROM jurisdictions
                             WHERE adm_level  = 1
                               AND deleted_at IS NULL
                               AND iso_code   IS NOT NULL
                             ORDER BY ST_Centroid(geom) <-> ST_Centroid(p.geom)
                             LIMIT 8
                        )
                        SELECT iso_code
                          FROM candidates
                         ORDER BY ST_Distance(
                                      ST_Boundary(geom)::geography,
                                      ST_Boundary(p.geom)::geography
                                  ) ASC,
                                  iso_code ASC
                         LIMIT 1
                    ) AS nearest_iso
                  FROM pieces p;
            END;
            $func$ LANGUAGE plpgsql;
        SQL);

        // ── Step 5 helper: apply one orphan piece with cascade ──────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_cross_iso_apply_orphan_piece(
                p_piece_geom  GEOMETRY,
                p_piece_pop   BIGINT,
                p_owning_iso  TEXT
            ) RETURNS TABLE(
                rows_updated     INT,
                cascaded_levels  INT[]
            ) AS $func$
            DECLARE
                v_levels        INT[];
                v_level         INT;
                v_winner_id     UUID;
                v_rows_updated  INT   := 0;
                v_cascaded      INT[] := ARRAY[]::INT[];
            BEGIN
                IF p_piece_pop IS NULL OR p_piece_pop <= 0 THEN
                    RETURN QUERY SELECT 0, ARRAY[]::INT[];
                    RETURN;
                END IF;

                -- Levels the owning iso exposes (ascending).
                SELECT array_agg(adm_level ORDER BY adm_level) INTO v_levels
                  FROM (
                      SELECT DISTINCT adm_level
                        FROM jurisdictions
                       WHERE iso_code   = p_owning_iso
                         AND adm_level  >= 1
                         AND deleted_at IS NULL
                  ) lvls;

                IF v_levels IS NULL THEN
                    RETURN QUERY SELECT 0, ARRAY[]::INT[];
                    RETURN;
                END IF;

                -- For each level: find nearest sibling within owning iso
                -- (top-8 by centroid GIST KNN, re-rank by boundary
                -- distance, tie-break by id for determinism). UN-simplified
                -- geom for distance to preserve tie integrity.
                FOREACH v_level IN ARRAY v_levels LOOP
                    WITH candidates AS (
                        SELECT id, geom
                          FROM jurisdictions
                         WHERE iso_code   = p_owning_iso
                           AND adm_level  = v_level
                           AND deleted_at IS NULL
                         ORDER BY ST_Centroid(geom) <-> ST_Centroid(p_piece_geom)
                         LIMIT 8
                    )
                    SELECT id INTO v_winner_id
                      FROM candidates
                     ORDER BY ST_Distance(
                                  ST_Boundary(geom)::geography,
                                  ST_Boundary(p_piece_geom)::geography
                              ) ASC,
                              id ASC
                     LIMIT 1;

                    IF v_winner_id IS NULL THEN
                        CONTINUE;
                    END IF;

                    UPDATE jurisdictions
                       SET population                      = COALESCE(population, 0) + p_piece_pop,
                           population_cross_iso_correction = COALESCE(population_cross_iso_correction, 0)
                                                             + p_piece_pop
                     WHERE id = v_winner_id;

                    v_rows_updated := v_rows_updated + 1;
                    v_cascaded     := array_append(v_cascaded, v_level);
                END LOOP;

                RETURN QUERY SELECT v_rows_updated, v_cascaded;
            END;
            $func$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS population_cross_iso_apply_orphan_piece(GEOMETRY, BIGINT, TEXT)');
        DB::statement('DROP FUNCTION IF EXISTS population_cross_iso_orphan_tile(INT, INT, DOUBLE PRECISION, SMALLINT)');
        DB::statement('DROP FUNCTION IF EXISTS population_correction_apply_gap_piece(TEXT, INT, GEOMETRY, BIGINT)');
        DB::statement('DROP FUNCTION IF EXISTS population_correction_gap_tile(TEXT, INT, INT, INT, DOUBLE PRECISION, SMALLINT)');
        DB::statement('DROP FUNCTION IF EXISTS population_correction_apply_overlap(UUID, UUID, SMALLINT)');
        DB::statement('DROP FUNCTION IF EXISTS population_correction_overlap_candidates(TEXT, INT)');
    }
};
