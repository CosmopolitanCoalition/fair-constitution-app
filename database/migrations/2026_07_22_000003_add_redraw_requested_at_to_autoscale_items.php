<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drift-repair flag (operator ruling 2026-07-22, "there should be no drift
 * in seat counts"): a repair requeue stamps this so ONE sweep attempt skips
 * ADOPT-NEVER-BULLDOZE and redraws the drifted active map through the
 * audited replace path. finishItem consumes the flag — a plain requeue
 * never bulldozes anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->timestampTz('redraw_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->dropColumn('redraw_requested_at');
        });
    }
};
