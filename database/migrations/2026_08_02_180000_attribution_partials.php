<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribution window-range partials (operator design 2026-08-02: "could
 * windows themselves be chunked among lanes?"). A monster attribution pair
 * pre-splits its deterministic window traversal into attribution_range
 * items; each range child writes its per-jurisdiction partial totals here
 * in bounded chunks, and the pair's coordinator merges them with one
 * GROUP BY at the barrier (per-pixel work is independent — a pair's answer
 * is the element-wise sum of its ranges' partials). Rows are scratch data:
 * the coordinator deletes its pair's rows after a successful merge+apply,
 * and a re-run range deletes its own slice's rows first (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribution_partials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->string('iso_code', 3);
            $table->integer('adm_level');
            $table->integer('win_start');           // the writing range's slice
            $table->uuid('jurisdiction_id');
            $table->bigInteger('pop');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['run_id', 'iso_code', 'adm_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_partials');
    }
};
