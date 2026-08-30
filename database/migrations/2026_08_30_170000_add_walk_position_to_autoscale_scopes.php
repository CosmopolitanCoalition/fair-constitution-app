<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE STEPPER ORDER FOR LANES (operator order 2026-08-30, benchmark 3):
 * every scope carries its position in the UI stepper's walk — post-order,
 * largest budget first among siblings, root last — so the multilane claim
 * ORDER matches the wizard exactly (Uttar Pradesh first on Earth, Earth
 * root last). NULL = pre-materialization rows; they sort after stamped
 * ones and keep the legacy order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->integer('walk_position')->nullable();
            $table->index(['run_id', 'status', 'walk_position'], 'autoscale_scopes_walk_idx');
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->dropIndex('autoscale_scopes_walk_idx');
            $table->dropColumn('walk_position');
        });
    }
};
