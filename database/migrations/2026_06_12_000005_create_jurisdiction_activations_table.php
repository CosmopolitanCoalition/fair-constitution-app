<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WI-7 — Jurisdiction activation lifecycle (WF-JUR-01 bootstrap tracker).
 *
 * A SEPARATE table (one row per jurisdiction, unique FK) so the PROTECTED
 * jurisdictions migration is never touched. Absence of a row = the
 * jurisdiction is dormant with boundary loaded (the 951k ETL rows get no
 * activation row until CLK-06 fires for them).
 *
 * State machine (Phase A slice of the jurisdiction entity machine):
 *
 *   boundary_loaded → critical_population → bootstrapping → self_governing
 *
 *  - critical_population : CLK-06 threshold crossed (verified residents ≥
 *                          resolved critical_population_threshold)
 *  - bootstrapping       : ActivationService::activate running the pipeline
 *                          (legislature sizing, institution stubs)
 *  - self_governing      : legislature row exists; institutions stubbed
 *
 * Later entity states (federation handshake, dissolution…) arrive with
 * their phases. `notes` carries pipeline breadcrumbs (per-step timestamps,
 * seat math inputs) for the Phase F bootstrap-tracker screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurisdiction_activations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id')->unique();
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            $table->string('state', 24)->default('boundary_loaded');

            $table->timestampTz('critical_population_at')->nullable();
            $table->timestampTz('activated_at')->nullable();

            $table->uuid('legislature_id')->nullable();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->nullOnDelete();

            $table->jsonb('notes')->default('{}');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('state');
        });

        DB::statement('ALTER TABLE jurisdiction_activations ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            "ALTER TABLE jurisdiction_activations ADD CONSTRAINT jurisdiction_activations_state_check " .
            "CHECK (state IN ('boundary_loaded', 'critical_population', 'bootstrapping', 'self_governing'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisdiction_activations');
    }
};
