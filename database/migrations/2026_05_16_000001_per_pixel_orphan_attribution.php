<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase T.5 — Per-pixel orphan attribution.
 *
 * Replaces the chunked + subdivide gap pass (migration
 * 2026_05_15_000001) with a true per-pixel implementation that
 * eliminates the overshoot mechanism observed in the live run.
 *
 * Background.
 *   The chunked-tile gap pass was a piece-level approximation of the
 *   operator's stated per-pixel rule. ST_Subdivide + ST_Intersection
 *   + ST_Union round-trips leave hairline mismatches along subdivide
 *   cut lines inside the L-polygons; ST_Difference materializes
 *   those hairlines as additional phantom gap pieces. population_within's
 *   ST_Clip counts any raster pixel whose centroid falls on a
 *   phantom hairline — and that pixel was already counted (correctly)
 *   in the polygon whose interior actually contains it. Net: every
 *   "phantom" piece causes a double-attribution and the gap correction
 *   overshoots the real deficit. 13 (iso, level) pairs flipped sign
 *   post-correction in the live run, with FRA L=5 the worst at 4×
 *   amplification (–928 k pre, +2.7 M post).
 *
 *   This replacement eliminates the mechanism. We classify every
 *   raster pixel directly via point-in-polygon against the
 *   ORIGINAL `jurisdictions.geom` (no subdivide artefacts). Pixels
 *   that no L-polygon contains are orphans; they get a single
 *   contribution to their nearest sibling via GIST KNN. Cross-ISO
 *   tree ownership is enforced by `ST_Contains(L1, pixel)` — pixels
 *   outside the iso's L=1 footprint never enter classification.
 *
 * Algorithm per (iso, level):
 *
 *   Step 0  — Reset population to baseline (idempotent re-run).
 *   Step 1  — Pairwise overlap detection (unchanged from prior
 *             migration; ST_Touches filter still applies). geoBoundaries
 *             produces 0 real overlaps in practice but kept defensively.
 *   Step 2  — Per-pixel orphan attribution:
 *             a. Determine relevant raster set (rasters touched by
 *                the iso's boundary tree).
 *             b. For each 4° × 4° tile that intersects L=1, enumerate
 *                pixels inside parent_tile from all relevant rasters.
 *             c. Pixel point-in-polygon against L-level polygons via
 *                LEFT JOIN with GIST index.
 *             d. Pixels with no containing polygon are orphans →
 *                find nearest L-polygon via `<->` KNN, attribute
 *                pixel value to that polygon.
 *
 * What stays identical from prior migrations:
 *   - Function signature `population_correction_pass(TEXT, INT, SMALLINT)`
 *     returning the same 5-column TABLE.
 *   - Audit columns (population_baseline, population_overlap_correction,
 *     population_gap_correction) from 2026_05_14_000001.
 *   - Snapshot helper `population_correction_snapshot_baseline(TEXT)`.
 *
 * What's dropped:
 *   - `_t3_l_pieces` and `_t3_l1_pieces` temp tables (subdivide no
 *     longer used for the gap pass).
 *   - Triple-overlap sanity sentinel (was best-effort, irrelevant
 *     once the per-pixel design eliminates the phantom problem).
 *     `triple_overlap_warnings` return column kept at 0 for
 *     schema-compatibility with the Python orchestrator.
 *
 * Performance characteristics:
 *   - Per-pixel work is dominated by GIST point-in-polygon lookups.
 *     For well-tessellated isos, most pixels hit a containing polygon
 *     in O(log N) and we skip them. Only orphans pay the KNN cost.
 *   - Tile-bounded geometry: each tile's pixel set is bounded by
 *     tile area; no monolithic ST_Union of country-scale L-polygons.
 *   - Expected runtime per iso (rough): tiny isos < 10 s; mid-size
 *     isos 30 s – 5 min; continental isos like IND L=6 may run
 *     longer due to per-pixel KNN cost (~10 min). The current
 *     chunked + subdivide pass runs IDN L=3 in ~30 min; per-pixel
 *     should match or beat that.
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

                v_overlap_count INT    := 0;
                v_overlap_pop   BIGINT := 0;
                v_gap_pieces    INT    := 0;
                v_gap_pop       BIGINT := 0;
                v_pair          RECORD;

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
                v_tile_geom    GEOMETRY;
                v_parent_tile  GEOMETRY;
            BEGIN
                -- Step 0 — idempotent reset to baseline. Safe to re-run.
                UPDATE jurisdictions
                SET population                    = population_baseline,
                    population_overlap_correction = 0,
                    population_gap_correction     = 0
                WHERE iso_code  = p_iso
                  AND adm_level = p_level
                  AND deleted_at IS NULL
                  AND population_baseline IS NOT NULL;

                -- Step 1 — pairwise overlap detection.
                -- ST_Touches filter excludes boundary-only adjacencies
                -- (the dominant case for tessellating polygons), keeping
                -- the ST_Intersection cost bounded to actual interior
                -- overlaps. geoBoundaries produces ~0 real overlaps;
                -- the loop is defensive.
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

                -- Step 2 — per-pixel orphan gap attribution.
                IF p_level >= 2 THEN
                    -- Get the iso's L=1 polygon. Defines the "claim
                    -- region" for this boundary tree.
                    SELECT geom INTO v_iso_l1_geom
                    FROM jurisdictions
                    WHERE iso_code  = p_iso
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

                        -- Determine relevant rasters: any worldpop raster
                        -- whose tile rows intersect the iso's L=1 or any
                        -- L-level polygon. For USA L=2 this is
                        -- {USA, PRI, GUM, VIR, ASM, MNP}; for PRI L=2
                        -- this is {PRI} alone.
                        CREATE TEMP TABLE IF NOT EXISTS _t5_relevant_isos (
                            iso_code TEXT PRIMARY KEY
                        ) ON COMMIT DROP;
                        TRUNCATE _t5_relevant_isos;
                        INSERT INTO _t5_relevant_isos (iso_code)
                        SELECT DISTINCT r.iso_code
                        FROM   worldpop_rasters r
                        JOIN   jurisdictions j
                          ON   j.iso_code = p_iso
                         AND   j.adm_level IN (1, p_level)
                         AND   j.deleted_at IS NULL
                        WHERE  ST_Intersects(r.rast, j.geom)
                          AND  r.year = p_year;

                        -- Accumulator for per-orphan contributions. We
                        -- buffer (winner_id, pop_value) rows across all
                        -- tiles, then aggregate + apply in a single
                        -- UPDATE at the end. Avoids many small UPDATEs.
                        CREATE TEMP TABLE IF NOT EXISTS _t5_orphan_contribs (
                            winner_id UUID,
                            pop_value BIGINT
                        ) ON COMMIT DROP;
                        TRUNCATE _t5_orphan_contribs;

                        FOR v_tx IN v_tx_min..v_tx_max LOOP
                            FOR v_ty IN v_ty_min..v_ty_max LOOP
                                v_tile_geom := ST_MakeEnvelope(
                                    v_tx * v_tile_size,
                                    v_ty * v_tile_size,
                                    (v_tx + 1) * v_tile_size,
                                    (v_ty + 1) * v_tile_size,
                                    4326
                                );

                                -- Skip tiles that don't intersect L=1.
                                IF NOT ST_Intersects(v_iso_l1_geom, v_tile_geom) THEN
                                    CONTINUE;
                                END IF;

                                v_parent_tile := ST_Intersection(v_iso_l1_geom, v_tile_geom);

                                -- Enumerate pixels in this tile that:
                                --   (a) come from relevant rasters
                                --   (b) sit inside parent_tile
                                --   (c) have non-zero value
                                -- Classify each pixel by containment in any
                                -- L-level polygon. Orphans are pixels with
                                -- no containing polygon. For each orphan,
                                -- find nearest L-polygon via GIST KNN and
                                -- accumulate the pixel value.
                                --
                                -- Implementation detail: the LEFT JOIN on
                                -- jurisdictions uses the GIST index (via
                                -- ST_Contains, which the planner can
                                -- index-accelerate). The point-in-polygon
                                -- test is exact at float precision — no
                                -- subdivide artefacts.
                                INSERT INTO _t5_orphan_contribs (winner_id, pop_value)
                                WITH pixel_pts AS (
                                    SELECT (px).geom AS pt, (px).val AS val
                                    FROM (
                                        SELECT ST_PixelAsCentroids(r.rast) AS px
                                        FROM   worldpop_rasters r
                                        JOIN   _t5_relevant_isos ri
                                          ON   r.iso_code = ri.iso_code
                                        WHERE  r.year = p_year
                                          AND  ST_Intersects(r.rast, v_parent_tile)
                                    ) raw
                                    WHERE (px).val > 0
                                      AND ST_Contains(v_parent_tile, (px).geom)
                                ),
                                classified AS (
                                    SELECT pp.pt, pp.val,
                                           EXISTS (
                                               SELECT 1
                                               FROM   jurisdictions j
                                               WHERE  j.iso_code  = p_iso
                                                 AND  j.adm_level = p_level
                                                 AND  j.deleted_at IS NULL
                                                 AND  ST_Contains(j.geom, pp.pt)
                                           ) AS has_owner
                                    FROM pixel_pts pp
                                )
                                SELECT
                                    -- For each orphan, find nearest L-polygon
                                    -- via the KNN <-> operator. GIST
                                    -- accelerated, returns the true nearest.
                                    (SELECT j.id
                                     FROM   jurisdictions j
                                     WHERE  j.iso_code  = p_iso
                                       AND  j.adm_level = p_level
                                       AND  j.deleted_at IS NULL
                                     ORDER BY j.geom <-> c.pt
                                     LIMIT 1) AS winner_id,
                                    c.val::BIGINT AS pop_value
                                FROM classified c
                                WHERE NOT c.has_owner;
                            END LOOP;
                        END LOOP;

                        -- Aggregate per-winner contributions and apply.
                        WITH aggregated AS (
                            SELECT winner_id, SUM(pop_value)::BIGINT AS total
                            FROM   _t5_orphan_contribs
                            WHERE  winner_id IS NOT NULL
                            GROUP  BY winner_id
                        )
                        UPDATE jurisdictions j
                        SET    population                = population + agg.total,
                               population_gap_correction = COALESCE(population_gap_correction, 0) + agg.total
                        FROM   aggregated agg
                        WHERE  j.id = agg.winner_id;

                        -- Stats for the return row.
                        SELECT COUNT(*)::INT, COALESCE(SUM(pop_value), 0)::BIGINT
                          INTO v_gap_pieces, v_gap_pop
                        FROM _t5_orphan_contribs
                        WHERE winner_id IS NOT NULL;
                    END IF;
                END IF;

                RETURN QUERY SELECT
                    v_overlap_count,
                    v_overlap_pop,
                    v_gap_pieces,        -- counts orphan PIXELS now, not gap pieces
                    v_gap_pop,           -- sum of orphan pixel values (= applied correction)
                    0;                   -- triple_overlap_warnings retired; kept for schema compat
            END;
            $func$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Roll back to the chunked + subdivide version
        // (`2026_05_15_000001`). Recommended only if the per-pixel
        // approach turns out to have a regression we couldn't
        // anticipate; otherwise this is roll-forward only.
        DB::statement('DROP FUNCTION IF EXISTS population_correction_pass(TEXT, INT, SMALLINT)');
    }
};
