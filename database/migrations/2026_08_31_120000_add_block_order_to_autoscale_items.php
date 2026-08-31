<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE BLOCK ORDER (operator ruling 2026-08-31, spoken table): the run's
 * priority is intrinsic to the claim, never staged by status flips.
 *
 *   block_rank  = adm_level * 2 + (0 composite | 1 leaf)
 *                 — planet first, then per layer: composites, then leaves.
 *   block_order = -population for composites (biggest first),
 *                 +population for leaves (smallest first — trivials lead).
 *
 * Workers claim only from the LOWEST unfinished block_rank; within it they
 * follow block_order, then the stamped scope walk. All lanes one direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->smallInteger('block_rank')->nullable();
            $table->bigInteger('block_order')->nullable();
            $table->index(['run_id', 'status', 'block_rank'], 'autoscale_items_block_idx');
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->dropIndex('autoscale_items_block_idx');
            $table->dropColumn(['block_rank', 'block_order']);
        });
    }
};
