<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase T.3 — per-ISO pixel-attribution correction (gap + overlap).
 *
 * Background.
 *   Within each ISO's polygon tree, the sibling polygons at level L should
 *   collectively tessellate the parent extent (the ISO's national polygon
 *   for L=1, or the union of L-1 polygons for L≥2). In practice
 *   geoBoundaries data has two small but cumulatively-significant defects:
 *
 *     - Same-level OVERLAP slivers: tiny regions where two L-level siblings
 *       overlap because of float-precision boundary jitter. Pixels in
 *       these slivers get counted in BOTH polygons by `population_within`,
 *       overstating L's total.
 *     - Same-level GAP pixels: micro-gaps between sibling polygons leave
 *       populated pixels unattributed to any L-level polygon. They roll
 *       up to parent_level but vanish from L's sum.
 *
 *   Together: ΣL(I)  ≠  Σpixels(I's raster). The per-ISO invariant the
 *   operator wants is exactly that equality.
 *
 * Correction rules (operator-confirmed):
 *   - Pixel in 1 polygon at level L → full value to that polygon
 *   - Pixel in N≥2 polygons (overlap sliver) → value/N to each (pairwise
 *     pass split-by-2; N≥3 is rare with geoBoundaries data; the
 *     pairwise pass under-counts triple-overlap pixels, but real
 *     occurrence is negligible — defended via a sanity-log only)
 *   - Pixel in zero polygons (gap) → full value to the geometrically-
 *     nearest sibling at level L (boundary-to-boundary distance, with
 *     centroid-distance prefilter to top-8); ties (within 100 m epsilon)
 *     split evenly.
 *
 * This migration ships:
 *   1. Three audit columns on `jurisdictions`:
 *        - population_baseline           : the pre-correction value, set
 *                                           once at first-correction-run
 *                                           per ISO and never touched again.
 *                                           Existing rows = NULL until the
 *                                           Python pass populates them.
 *        - population_overlap_correction : how much overlap-split removed
 *                                           (always ≤ 0).
 *        - population_gap_correction     : how much gap-clamp added
 *                                           (always ≥ 0).
 *
 *      `population` itself becomes the canonical POST-correction value, so
 *      every downstream consumer (DataReviewService, the viewer's stat
 *      headers, apportionment) reads corrected numbers with no opt-in.
 *
 *   2. `population_correction_pass(p_iso TEXT, p_level INT, p_year SMALLINT
 *      DEFAULT 2023)` PL/pgSQL function. Idempotent: each call resets the
 *      (iso, level) rows to baseline first, then re-applies overlap-split
 *      and gap-clamp. Re-running is safe and gives the same result.
 *
 *   3. A complementary `population_correction_snapshot_baseline(p_iso TEXT)`
 *      function: copies `population` → `population_baseline` for an ISO's
 *      rows that don't yet have a baseline. Called once per ISO by the
 *      Python orchestrator before invoking population_correction_pass on
 *      its levels.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Audit columns ────────────────────────────────────────────
        Schema::table('jurisdictions', function ($table) {
            $table->bigInteger('population_baseline')->nullable()
                ->comment('Phase T.3: pre-correction population. Snapshotted once per ISO at the first pixel-attribution-correction run; never overwritten after that.');
            $table->bigInteger('population_overlap_correction')->default(0)
                ->comment('Phase T.3: amount subtracted from population due to overlap-sliver split-among-N. Always ≤ 0.');
            $table->bigInteger('population_gap_correction')->default(0)
                ->comment('Phase T.3: amount added to population due to gap-pixel nearest-sibling clamp. Always ≥ 0.');
        });

        // ── 2. Baseline snapshot helper ─────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_correction_snapshot_baseline(
                p_iso TEXT
            ) RETURNS INT AS $$
                WITH updated AS (
                    UPDATE jurisdictions
                    SET population_baseline = population
                    WHERE iso_code = p_iso
                      AND population_baseline IS NULL
                      AND population IS NOT NULL
                      AND deleted_at IS NULL
                    RETURNING 1
                )
                SELECT COUNT(*)::INT FROM updated;
            $$ LANGUAGE SQL;
        SQL);

        // ── 3. Correction pass ──────────────────────────────────────────
        // The big one. Resets the (iso, level) rows to baseline, then runs
        // overlap-detection (pairwise split-by-2) followed by gap-detection
        // (nearest-sibling clamp by boundary distance with tie-split).
        //
        // The function returns one row of summary counts so the Python
        // orchestrator can log per-iso-level deltas.
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
            BEGIN
                -- Step 0 — idempotent reset. Re-running on the same (iso,
                -- level) returns to baseline first, so corrections never
                -- compound. Rows that haven't been baseline-snapshotted yet
                -- are skipped (their population stays as-is until the
                -- snapshot helper runs).
                UPDATE jurisdictions
                SET population                  = population_baseline,
                    population_overlap_correction = 0,
                    population_gap_correction     = 0
                WHERE iso_code  = p_iso
                  AND adm_level = p_level
                  AND deleted_at IS NULL
                  AND population_baseline IS NOT NULL;

                -- Step 1 — pairwise overlap detection. Each (A, B) overlap
                -- subtracts overlap_pop/2 from both A's and B's population.
                -- 1.0 m² area threshold filters float-precision boundary
                -- jitter (real slivers >> 1 m²); ST_MakeValid resolves
                -- GEOSContains topology exceptions on borderline-valid input.
                FOR v_pair IN
                    SELECT
                        j1.id AS id1, j2.id AS id2,
                        population_within(
                            p_iso::VARCHAR(3),
                            ST_MakeValid(ST_Intersection(j1.geom, j2.geom)),
                            p_year
                        ) AS overlap_pop,
                        ST_Area(ST_Intersection(j1.geom, j2.geom)::geography) AS overlap_area
                    FROM jurisdictions j1
                    JOIN jurisdictions j2
                      ON j1.iso_code  = j2.iso_code
                     AND j1.adm_level = j2.adm_level
                     AND j1.id < j2.id
                     AND ST_Intersects(j1.geom, j2.geom)
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

                -- Defensive: count triple-overlap pixels (>=3-way) and
                -- log them via the return row. Real geoBoundaries data
                -- almost never produces these (sibling polygons share at
                -- most pairwise slivers from coastline jitter); if any
                -- show up the operator can decide whether to follow up.
                -- This is just a sanity sentinel — no correction applied.
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
                    JOIN jurisdictions j3
                      ON j1.iso_code  = j3.iso_code
                     AND j1.adm_level = j3.adm_level
                     AND j2.id < j3.id
                     AND ST_Intersects(j1.geom, j3.geom)
                     AND ST_Intersects(j2.geom, j3.geom)
                    WHERE j1.iso_code  = p_iso
                      AND j1.adm_level = p_level
                      AND j1.deleted_at IS NULL
                      AND j2.deleted_at IS NULL
                      AND j3.deleted_at IS NULL
                ) triples
                WHERE NOT ST_IsEmpty(g)
                  AND ST_Area(g::geography) > 1.0;

                -- Step 2 + 3 — gap detection and nearest-sibling clamp.
                -- Skip L=1 entirely: parent_extent = the L=1 polygon itself,
                -- so gap = polygon - polygon = ∅ by construction.
                --
                -- For L≥2 we deviate from the original plan's "union of L-1
                -- polygons" parent_extent. Some isos skip intermediate
                -- levels (PRI has L=1 and L=3 but no L=2; certain FRA
                -- tiers expose L=1 and L=4 with nothing between). Using
                -- L-1 directly would compute parent_extent = ∅ in those
                -- cases and the gap pass would silently do nothing — but
                -- the operator's per-ISO invariant requires every level
                -- the iso exposes to sum to the raster total. Using L=1
                -- (the iso's national boundary) as the parent_extent for
                -- every L≥2 achieves the invariant uniformly with no
                -- level-skip special cases. The "gap" pixels for an
                -- intermediate-skipping iso then include both the
                -- traditional micro-gaps AND any region the L-level
                -- polygons fail to cover (which the L-1 design would
                -- have ignored).
                IF p_level >= 2 THEN
                    FOR v_gap_piece IN
                        WITH parent_extent AS (
                            SELECT geom
                            FROM jurisdictions
                            WHERE iso_code  = p_iso
                              AND adm_level = 1
                              AND deleted_at IS NULL
                            LIMIT 1
                        ),
                        this_level_union AS (
                            SELECT ST_Union(geom) AS geom
                            FROM jurisdictions
                            WHERE iso_code  = p_iso
                              AND adm_level = p_level
                              AND deleted_at IS NULL
                        ),
                        diff AS (
                            SELECT (ST_Dump(
                                ST_Difference(
                                    ST_MakeValid(pe.geom),
                                    ST_MakeValid(tlu.geom)
                                )
                            )).geom AS geom
                            FROM parent_extent pe, this_level_union tlu
                            WHERE pe.geom IS NOT NULL AND tlu.geom IS NOT NULL
                        )
                        SELECT
                            geom,
                            population_within(p_iso::VARCHAR(3), geom, p_year) AS gap_pop
                        FROM diff
                        WHERE NOT ST_IsEmpty(geom)
                          -- 100 m² floor — below this is sub-pixel boundary
                          -- jitter, not a real gap with population to
                          -- attribute.
                          AND ST_Area(geom::geography) > 100.0
                    LOOP
                        IF v_gap_piece.gap_pop IS NULL OR v_gap_piece.gap_pop <= 0 THEN
                            CONTINUE;
                        END IF;

                        v_gap_pieces := v_gap_pieces + 1;
                        v_gap_pop    := v_gap_pop + v_gap_piece.gap_pop;

                        -- Find nearest sibling(s) to this gap piece.
                        -- Two-stage: (1) top-8 by centroid distance (cheap
                        -- coarse filter using the GIST <-> KNN operator);
                        -- (2) within that 8, rank by BOUNDARY-to-boundary
                        -- distance and pick winners within a 100 m
                        -- epsilon. Boundary distance correctly handles
                        -- long thin coastal gaps where a polygon whose
                        -- centroid is far but whose boundary is close
                        -- should win.
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
                        WHERE bd - (SELECT MIN(bd) FROM ranked) < 100.0;

                        v_n_winners := COALESCE(array_length(v_winner_ids, 1), 0);
                        IF v_n_winners = 0 THEN
                            -- Defensive — shouldn't happen (we have ≥1
                            -- L-level row by the time we reach here), but
                            -- skip rather than divide by zero.
                            CONTINUE;
                        END IF;

                        v_per_winner := v_gap_piece.gap_pop / v_n_winners;

                        UPDATE jurisdictions
                        SET population                = population + v_per_winner,
                            population_gap_correction = COALESCE(population_gap_correction, 0) + v_per_winner
                        WHERE id = ANY(v_winner_ids);
                    END LOOP;
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
        DB::statement('DROP FUNCTION IF EXISTS population_correction_snapshot_baseline(TEXT)');
        Schema::table('jurisdictions', function ($table) {
            $table->dropColumn([
                'population_baseline',
                'population_overlap_correction',
                'population_gap_correction',
            ]);
        });
    }
};
