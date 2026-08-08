<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE THREE ACTIVATION MODES (operator, 2026-08-08 — the manual-first arc's
 * mode selector). Stored at map acceptance; read by the accept flow, the
 * autoscale completion chain, and the Jurisdictions-list controls.
 *
 *   eager       — "Activate & Scale Institutions Now": acceptance starts the
 *                 full-scale build (autoscale sizing + maps), and completion
 *                 chains the institution provisioning shell set.
 *   population  — "As Players Join": nothing pre-built beyond the
 *                 unconditional civic spaces; CLK-06 (EvaluateClocksJob →
 *                 EvaluateCriticalPopulationJob, armed every minute) boots
 *                 each place when verified residents cross its threshold.
 *   manual      — operator/players build with the Activate controls and the
 *                 governance forms; no formula consulted.
 *
 * simulate_at_scale — dev-only sub-option of eager (gated on
 * game_mode='sandbox'): after the eager build completes, the simulation
 * populates the world through the real governance engine.
 *
 * Additive-only, REAL-dated (post-flatten law).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('institution_scale_mode', 20)->default('eager');
            $table->boolean('simulate_at_scale')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['institution_scale_mode', 'simulate_at_scale']);
        });
    }
};
