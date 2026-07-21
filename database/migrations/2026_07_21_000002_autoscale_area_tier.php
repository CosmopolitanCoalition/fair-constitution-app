<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HEAVY-LANE ORDERING (operator ruling 2026-07-21): the enumeration key's
 * adm_level DESC rung was a COST PROXY that leaked — the true per-scope cost
 * driver is geometry size (grid-computation time), so every est band ended in
 * a consecutive block of shallow-admin giants that captured all workers at
 * once (the est-2 tail: rate 20.8k/h → ~1k/h, two OOM episodes).
 *
 * area_tier = the pixelGrid ladder's own area buckets, computed from the
 * bbox (header-only, cheap; over-estimates for diagonal coastal shapes,
 * which errs HEAVY — the safe direction for both ordering and the cap):
 *   1 ≤ 300 km² · 2 ≤ 3k · 3 ≤ 30k · 4 ≤ 300k · 5 above.
 * Ordering: est ASC, cascade ASC, area_tier ASC, adm DESC, pop ASC.
 * Claim cap: tiers ≥ 4 are the HEAVY lane — at most 20% of worker threads,
 * lifted when no light work remains (AutoscaleClaims).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->smallInteger('area_tier')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->dropColumn('area_tier');
        });
    }
};
