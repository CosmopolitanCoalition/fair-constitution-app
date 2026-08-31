<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE SHAPE-STATS STORE (operator order 2026-08-30, "remember what it
 * made"). A district's three geometric facts — hull ratio, part count,
 * contiguity — are pure functions of its member set. The store remembers
 * them under a fingerprint of the sorted member ids, so a redraw recalls
 * three numbers instead of re-fusing coastline geometry (Russia paid
 * three minutes per draw for these numbers). No geometry is stored:
 * ~60 bytes per row. Draft rows (kept=false, from repair comparisons)
 * purge at every sweep's end per the operator's rule; winner rows
 * (kept=true) persist until boundary re-ingestion clears the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('district_shape_stats', function (Blueprint $table) {
            $table->char('member_fingerprint', 32)->primary();
            $table->double('convex_hull_ratio')->nullable();
            $table->integer('num_geom_parts')->nullable();
            $table->boolean('is_contiguous')->nullable();   // null on draft rows (hull-only)
            $table->boolean('kept')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('district_shape_stats');
    }
};
