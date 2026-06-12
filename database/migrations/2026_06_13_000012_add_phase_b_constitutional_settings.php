<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-12 — Phase B amendable settings on `constitutional_settings`
 * (PHASE_B_DESIGN_schema_lifecycle §A B-12).
 *
 *  - finalist_multiplier — CLK-21: X = multiplier × seats, resolved per
 *    jurisdiction at race creation and FROZEN into
 *    election_races.finalist_count. Amendable; bounds 1–10 land in
 *    ConstitutionalValidator::SETTING_BOUNDS (WI-B4), citation
 *    "Art. II §2 · as implemented".
 *  - ranked_window_days — length of the ranked-voting window. Amendable
 *    (as implemented); bounds 1–60 (WI-B4).
 *  - approval_min_days — minimum approval-phase length before a cutoff may
 *    be set. Bootstrap/demo elections compress it via
 *    config('cga.election_demo_compression') — config, never data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->smallInteger('finalist_multiplier')->default(3)
                ->comment('CLK-21: finalist count X = multiplier × seats, frozen per race at creation');
            $table->smallInteger('ranked_window_days')->default(14)
                ->comment('Length of the ranked-voting window (days)');
            $table->smallInteger('approval_min_days')->default(30)
                ->comment('Minimum approval-phase length before a finalist cutoff may be set (days)');
        });
    }

    public function down(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn(['finalist_multiplier', 'ranked_window_days', 'approval_min_days']);
        });
    }
};
