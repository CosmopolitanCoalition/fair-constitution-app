<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PER-STEP TIMINGS (2026-08-09, the São Paulo runtime). The districting path
 * carried no elapsed measurement of any kind — a repo-wide hrtime/microtime
 * search hit only AdjacencyPrecompute — so "the autoseeder is slow on this
 * scope" could be observed but never attributed. Every performance claim about
 * Step 7 vs the Step-8 search vs Step-12's ST_Union was an estimate.
 *
 * One additive nullable column, written by the heartbeat that already fires
 * (DistrictingService::publishMassProgress), so measurement costs zero extra
 * round trips: {"ms": {"phaseA.k2": 41230, ...}, "n": {"phaseA.k2": 639}}.
 * Accumulated wall time per labelled phase plus the call count, which together
 * give the per-call cost the estimates were guessing at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->jsonb('step_timings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->dropColumn('step_timings');
        });
    }
};
