<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE MEET-IN-THE-MIDDLE ORDER (operator order 2026-08-31): the bottom-up
 * half of the lane pool claims scopes by this key — deepest admin layer
 * first (neighborhoods, then townships, municipalities, counties,
 * states, countries, the planet), lowest population first within each
 * layer — so the trivial mass clears immediately while the top-down half
 * grinds the giants, and the two directions meet in the middle. Ties at
 * the meeting point go to the top-down side (its claim locks first).
 * NULL = pre-materialization rows; they sort after stamped ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->bigInteger('reverse_position')->nullable();
            $table->index(['run_id', 'status', 'reverse_position'], 'autoscale_scopes_reverse_idx');
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->dropIndex('autoscale_scopes_reverse_idx');
            $table->dropColumn('reverse_position');
        });
    }
};
