<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase T.8 Step 5 — Audit column for cross-iso orphan attribution.
 *
 * Background.
 *   After Phase 2 baseline + within-iso correction (T.8 Steps 0-3),
 *   the live DB still has ~52.5 M people existing as raster pixels
 *   that don't fall inside any iso's L=1 polygon. This is between-iso
 *   tessellation slack: international-border seams where two ISOs'
 *   simplified L=1 outlines don't share an exact edge, coastline
 *   pixels in the raster outside the simplified iso boundary, and
 *   disputed-territory pixels not in any tree.
 *
 *   Step 5 (a global per-tile pass) finds these orphan pieces and
 *   attributes them to the nearest iso's L=1 with cascade through the
 *   iso's internal levels so the per-iso invariant SUM(L_n) = L=1
 *   stays exact at every level.
 *
 *   This column records, per row, how much of that row's `population`
 *   value came from cross-iso orphan attribution. Always ≥ 0. Lets
 *   the operator distinguish:
 *     - `population_baseline`              — own-tree raster total
 *     - `population_overlap_correction`    — within-iso overlap fix
 *                                             (always ≤ 0)
 *     - `population_gap_correction`        — within-iso gap clamp
 *                                             (always ≥ 0)
 *     - `population_cross_iso_correction`  — cross-iso orphan credit
 *                                             (always ≥ 0)
 *     - `population`                       — sum of the above
 *
 *   The cascade design adds the same orphan piece's pop to each
 *   level (L=1, then nearest L=2 sibling, then nearest L=3 child of
 *   that L=2, etc.) so SUM(L_n) = L=1 for each ISO at every level it
 *   exposes — same invariant the within-iso correction preserves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurisdictions', function ($table) {
            $table->bigInteger('population_cross_iso_correction')
                ->default(0)
                ->comment('Phase T.8 Step 5: pop added from cross-iso orphan pixel attribution, cascaded through every level the iso exposes. Always >= 0.');
        });
    }

    public function down(): void
    {
        Schema::table('jurisdictions', function ($table) {
            $table->dropColumn('population_cross_iso_correction');
        });
    }
};
