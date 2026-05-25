<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rip out Phase 2b pixel attribution correction (the T.8 line of work).
 *
 * Why this migration exists.
 *   The within-iso gap/overlap correction (T.8 Steps 0-3) and cross-iso
 *   orphan attribution (T.8 Step 5a/5b) produced row-level data that is
 *   fundamentally wrong, even when per-iso invariants held:
 *
 *     - IND L=6 hamlets like Safajing Teron (baseline 129) got credited
 *       with +1.7 M people because a 6.85 M gap piece tied 4 nearest
 *       siblings within 100 m
 *     - CHN L=4 Xiuyu District (baseline 606 k) got +7.69 M from one
 *       mainland gap piece dump
 *     - IND L=6 total: +27.3 M redistributed across 10 227 rows, with
 *       the top contributions wrong by 4+ orders of magnitude
 *
 *   Root cause: the nearest-sibling clamp doesn't degrade gracefully
 *   when an L-level fails to fully tessellate the iso's L=1. Sparse-
 *   coverage areas (IND tribal/Himalayan subdistricts, partial CHN L=4
 *   coverage) leave huge orphan regions that get dumped on whichever
 *   small adjacent polygon happens to be the geometric "nearest".
 *
 *   Additionally the correction passes are time-consuming (>10 h for a
 *   full sweep at 85-90 % container memory). The cost/benefit isn't
 *   there.
 *
 *   Decision (2026-05-22): abandon the correction approach entirely.
 *   Use Phase 2's baseline injection (`population_within` per polygon)
 *   + per-row + global topological raster fallback as the canonical
 *   population values.
 *
 * What this migration does.
 *   1. UPDATE jurisdictions.population = COALESCE(population_baseline,
 *      population) — restores every row that was touched by the
 *      correction pass back to its Phase 2 baseline value. Rows whose
 *      baseline was never snapshotted (isos the in-flight run hadn't
 *      reached) already have Phase 2 baseline in `population` and are
 *      left alone by COALESCE.
 *   2. UPDATE jurisdictions.population_baseline = population WHERE
 *      baseline IS NULL — backfills the baseline column so its
 *      semantics are uniform post-rip ("baseline = Phase 2 baseline
 *      value, snapshotted at the moment the correction phase was
 *      ripped"). Kept per operator wording ("the baseline pops are
 *      still in their own columns").
 *   3. DROP TABLE the two correction worktables.
 *   4. ALTER TABLE jurisdictions DROP the three correction audit
 *      columns (overlap, gap, cross-iso). KEEP `population_baseline`.
 *   5. DROP FUNCTION every correction SQL helper.
 *
 *   The five historical correction migrations
 *   (2026_05_14_000001, 2026_05_14_000002, 2026_05_15_000001,
 *   2026_05_16_000001, 2026_05_17_000001, 2026_05_18_000001-000004)
 *   stay in the migrations table as the audit trail of what was tried.
 *   This migration is the canonical "we tried this; it didn't work;
 *   here's the cleanup" statement.
 *
 * Rollback.
 *   `down()` is best-effort no-op. The dropped columns can be re-added
 *   but with NULL data (the corrections themselves can't be recomputed
 *   without re-running the abandoned pipeline). The dropped tables can
 *   be CREATE'd but empty. The SQL functions would need to be
 *   reinstated from the historical migration files. If you ever want to
 *   try corrections again, start from the clean Phase 2 baseline that
 *   this migration restores.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Restore population to Phase 2 baseline ───────────────────
        // Rows the correction pass touched: population = baseline + deltas.
        // Restore them to baseline. Rows the correction pass didn't reach:
        // baseline IS NULL, population already = Phase 2 baseline value,
        // COALESCE leaves them alone.
        DB::statement(<<<'SQL'
            UPDATE jurisdictions
               SET population = COALESCE(population_baseline, population)
             WHERE deleted_at IS NULL
        SQL);

        // ── 2. Backfill baseline column for rows that never got a snapshot
        // (isos the in-flight run hadn't reached). After this step every
        // row at adm_level >= 1 with non-null population has a matching
        // baseline, so the column has a uniform meaning going forward.
        DB::statement(<<<'SQL'
            UPDATE jurisdictions
               SET population_baseline = population
             WHERE population_baseline IS NULL
               AND population         IS NOT NULL
               AND adm_level          >= 1
               AND deleted_at         IS NULL
        SQL);

        // ── 3. Drop correction worktables ──────────────────────────────
        Schema::dropIfExists('cross_iso_orphan_pieces');
        Schema::dropIfExists('correction_chunk_log');

        // ── 4. Drop the three correction audit columns ─────────────────
        // KEEP `population_baseline` per operator wording (audit + safety
        // net in case future inspection needs to compare against
        // pre-correction values).
        Schema::table('jurisdictions', function ($table) {
            $table->dropColumn([
                'population_overlap_correction',
                'population_gap_correction',
                'population_cross_iso_correction',
            ]);
        });

        // ── 5. Drop correction SQL functions ───────────────────────────
        // Order: drop apply/wrapper functions first, then helpers they
        // referenced. CASCADE not used — drop in dependency order so any
        // typo surfaces as a clean error rather than silently dropping
        // something else.
        $functions = [
            'population_correction_pass(TEXT, INT, SMALLINT)',
            'population_correction_apply_overlap(UUID, UUID, SMALLINT)',
            'population_correction_gap_tile(TEXT, INT, INT, INT, DOUBLE PRECISION, SMALLINT, GEOMETRY)',
            'population_correction_apply_gap_piece(TEXT, INT, GEOMETRY, BIGINT)',
            'population_correction_overlap_candidates(TEXT, INT)',
            'population_correction_snapshot_baseline(TEXT)',
            'population_cross_iso_orphan_tile(INT, INT, DOUBLE PRECISION, SMALLINT)',
            'population_cross_iso_apply_orphan_piece(GEOMETRY, BIGINT, TEXT)',
            'cross_iso_orphan_rewind_iso(TEXT)',
            'population_within_topological(VARCHAR, GEOMETRY, SMALLINT)',
        ];
        foreach ($functions as $sig) {
            DB::statement("DROP FUNCTION IF EXISTS {$sig}");
        }
    }

    public function down(): void
    {
        // Best-effort no-op. See class docstring.
        //
        // To resurrect the correction infrastructure, you would need to:
        //   1. Re-run migrations 2026_05_14_000001..2026_05_18_000004
        //      (already present in the migrations table — would need to
        //      be removed first to allow re-running).
        //   2. Re-execute the entire Phase 2b pipeline to populate the
        //      correction columns + worktables.
        // The dropped data cannot be reconstructed from this migration
        // alone — `up()` is intentionally one-way.
    }
};
