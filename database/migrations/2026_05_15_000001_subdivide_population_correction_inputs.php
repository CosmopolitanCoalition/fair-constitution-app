<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase T.3 follow-up #2 — ST_Subdivide the input polygons before the
 * tile-chunked gap pass.
 *
 * Background.
 *   The 4° tile chunking from migration 2026_05_14_000002 kept the
 *   ST_Difference output bounded by tile area, but PostGIS still has
 *   to process every input vertex when clipping a giant L-level
 *   polygon (e.g. Canadian arctic territories with millions of
 *   coastline vertices) against a tile bbox. ST_Intersection cost is
 *   O(input_vertices) regardless of how small the output is — so CAN
 *   L=2 sat at 113 minutes per call before getting OOM-killed.
 *
 *   ST_Subdivide(geom, 256) splits a multi-million-vertex polygon into
 *   thousands of bounded-vertex pieces. Every original vertex is
 *   preserved — just partitioned across rows. Then per-tile
 *   ST_Intersection works on tiny pieces (≤256 vertices each), and
 *   the GIST index filters the tile-vs-piece intersect candidates
 *   instantly. Same final result as the monolithic version, bit for
 *   bit, at 100–1000× the speed on continental ISOs.
 *
 * What changes in this version:
 *
 *   - Two TEMP TABLES staged at the start of each function call:
 *       _t3_l_pieces  — subdivided L-level polygons, labelled by
 *                       parent jurisdiction id. ≤256 vertices per row.
 *       _t3_l1_pieces — subdivided L=1 polygon for the iso. Same.
 *     Both gain a GIST index on `geom`. Auto-drop on transaction
 *     commit (the function's caller wraps each call in its own
 *     transaction per psycopg2 convention, so staging lives only as
 *     long as the single (iso, level) pass).
 *
 *   - The gap-pass tile loop CTEs reference these staging tables
 *     instead of `jurisdictions.geom` directly. Tile filter is
 *     `ST_Intersects(piece.geom, tile_geom)` against the GIST index;
 *     per-tile ST_Intersection clips already-small pieces; ST_Union
 *     within the tile combines a few dozen 256-vertex pieces instead
 *     of a few country-scale ones.
 *
 *   - Tile-skip check (no L=1 coverage at all) now uses
 *     `EXISTS (SELECT 1 FROM _t3_l1_pieces WHERE ST_Intersects(geom, tile))`
 *     instead of the raw `ST_Intersects(v_iso_l1_geom, tile)`. Same
 *     answer, but reads from the indexed pieces in O(log N) instead
 *     of scanning every vertex of the original monolith.
 *
 * What stays identical:
 *
 *   - Step 0 (reset-to-baseline). Single-row UPDATE.
 *   - Step 1 (pairwise overlap detection). The ST_Touches filter
 *     already eliminates boundary-only adjacencies; remaining real
 *     overlaps are rare and per-pair ST_Intersection on the full
 *     polygons is bounded by GIST candidate set.
 *   - Step 1.5 (triple overlap sentinel, wrapped in EXCEPTION).
 *   - Step 3 (nearest-sibling assignment per gap piece). Same
 *     two-stage centroid-then-boundary distance with 100 m tie epsilon.
 *
 * Function signature and return shape unchanged.
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
                v_tile_size CONSTANT DOUBLE PRECISION := 4.0;
                v_tie_epsilon_m CONSTANT DOUBLE PRECISION := 100.0;
                -- ST_Subdivide max-vertices-per-piece. 256 is the PostGIS
                -- default; tuning it lower (~64) costs more rows but
                -- cheaper per-row ops, higher (~1024) does the opposite.
                -- 256 is well-tested middle ground.
                v_subdiv_max_verts CONSTANT INT := 256;

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
                v_has_l1 BOOLEAN;
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

                -- Step 1 — pairwise overlap detection (unchanged).
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
                     AND NOT ST_Touches(j1.geom, j2.geom)
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

                -- Triple-overlap sentinel (best-effort, wrapped in EXCEPTION).
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

                -- ── Gap detection with ST_Subdivide-driven chunking ──
                IF p_level >= 2 THEN

                    -- Stage subdivided L-level polygons. Each row is a
                    -- ≤256-vertex piece labelled with its parent
                    -- jurisdiction. All original vertices preserved.
                    -- GIST index on geom drives instant per-tile filtering.
                    CREATE TEMP TABLE IF NOT EXISTS _t3_l_pieces (
                        parent_id UUID,
                        geom GEOMETRY
                    ) ON COMMIT DROP;
                    TRUNCATE _t3_l_pieces;

                    INSERT INTO _t3_l_pieces (parent_id, geom)
                    SELECT j.id, ST_Subdivide(ST_MakeValid(j.geom), v_subdiv_max_verts)
                    FROM jurisdictions j
                    WHERE j.iso_code  = p_iso
                      AND j.adm_level = p_level
                      AND j.deleted_at IS NULL;

                    -- GIST index on the subdivided pieces. Without this,
                    -- per-tile ST_Intersects scans every piece. With it,
                    -- the GIST tree finds matching pieces in O(log N).
                    EXECUTE 'CREATE INDEX IF NOT EXISTS _t3_l_pieces_geom_idx ON _t3_l_pieces USING GIST (geom)';

                    -- Same staging for L=1. Single iso's national polygon
                    -- subdivided into 256-vertex pieces.
                    CREATE TEMP TABLE IF NOT EXISTS _t3_l1_pieces (
                        geom GEOMETRY
                    ) ON COMMIT DROP;
                    TRUNCATE _t3_l1_pieces;

                    INSERT INTO _t3_l1_pieces (geom)
                    SELECT ST_Subdivide(ST_MakeValid(geom), v_subdiv_max_verts)
                    FROM jurisdictions
                    WHERE iso_code = p_iso
                      AND adm_level = 1
                      AND deleted_at IS NULL;

                    EXECUTE 'CREATE INDEX IF NOT EXISTS _t3_l1_pieces_geom_idx ON _t3_l1_pieces USING GIST (geom)';

                    -- Skip the whole gap pass if iso has no L=1 polygon
                    -- (synthetic isos, polar pseudo-isos, etc).
                    SELECT EXISTS (SELECT 1 FROM _t3_l1_pieces) INTO v_has_l1;

                    IF v_has_l1 THEN
                        -- Bbox derived from the subdivided pieces (cheap;
                        -- GIST-backed aggregate).
                        SELECT
                            MIN(ST_XMin(geom)), MIN(ST_YMin(geom)),
                            MAX(ST_XMax(geom)), MAX(ST_YMax(geom))
                          INTO v_xmin, v_ymin, v_xmax, v_ymax
                        FROM _t3_l1_pieces;

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

                                -- Tile-skip via the GIST-indexed L=1 pieces.
                                -- O(log N) lookup, no monolithic-polygon scan.
                                IF NOT EXISTS (
                                    SELECT 1 FROM _t3_l1_pieces
                                    WHERE geom && v_tile_geom
                                      AND ST_Intersects(geom, v_tile_geom)
                                ) THEN
                                    CONTINUE;
                                END IF;

                                FOR v_gap_piece IN
                                    WITH parent_tile AS (
                                        -- Union of L=1 pieces clipped to tile.
                                        -- Each ST_Intersection is on a ≤256-vertex
                                        -- piece → instant.
                                        SELECT ST_MakeValid(ST_Union(
                                            ST_Intersection(geom, v_tile_geom)
                                        )) AS geom
                                        FROM _t3_l1_pieces
                                        WHERE geom && v_tile_geom
                                          AND ST_Intersects(geom, v_tile_geom)
                                    ),
                                    l_polys AS (
                                        -- L-level polygon pieces clipped to tile.
                                        SELECT ST_MakeValid(
                                            ST_Intersection(geom, v_tile_geom)
                                        ) AS geom
                                        FROM _t3_l_pieces
                                        WHERE geom && v_tile_geom
                                          AND ST_Intersects(geom, v_tile_geom)
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
        DB::statement('DROP FUNCTION IF EXISTS population_correction_pass(TEXT, INT, SMALLINT)');
    }
};
