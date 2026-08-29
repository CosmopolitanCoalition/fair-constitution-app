<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE PARADIGM-COMPLIANT MAP SWEEP (operator order 2026-08-29).
 *
 * The monolithic map sweep (one job walking every scope in one process) died
 * twice at the same monster scope: memory accumulates across scopes until the
 * kernel kills the process — the ETL paradigm's banned shape. This table is
 * the sweep's work pile: one row per (map, scope), claimed by short-lived
 * lane jobs that draw ONE scope and exit. Chunkable, resumable (a kill costs
 * one scope), visible (the table is the progress), lanes derived from host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_scope_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legislature_id')->index();
            $table->uuid('map_id');
            $table->uuid('scope_id');
            $table->bigInteger('est_cost')->default(0);
            $table->string('status', 16)->default('pending'); // pending|running|done|review|failed
            $table->uuid('claim_token')->nullable();
            $table->text('reason')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['map_id', 'status']);
            $table->unique(['map_id', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_scope_items');
    }
};
