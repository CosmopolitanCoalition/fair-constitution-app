<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Autoscale run state — additive on top of the flattened baseline
 * (REAL-dated, 2026-07-18).
 *
 * Map-data acceptance kicks off governance for ALL jurisdictions: size every
 * legislature, then district-map every one (48k parent sweeps + ~903k
 * single-district leaf councils). These two tables are the durable spine of
 * that run:
 *
 *  1. autoscale_runs — one row per full-scale run. Holds phase status and
 *     denormalised counters the Step-3 dashboard polls.
 *
 *  2. autoscale_items — one row per legislature to map. This table IS the
 *     resume cursor (done items are skipped on re-trigger), the review list
 *     (rejected engine filings roll back with the sweep's per-scope
 *     transaction, so a cache entry is not durable enough), and the
 *     dashboard's per-item data.
 *
 * Σ-seat drift vs type_a_seats is recorded on items as INFORMATIONAL only —
 * the seating law (2026-07-13) forbids total-forcing; drift is the drawing's
 * defect and ships flagged, never "fixed" by a redistribution loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autoscale_runs', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            // queued | sizing | mapping | done | halted | failed
            $table->string('status', 16)->default('queued');
            // Deepest adm level to size/map (planet default: full depth).
            $table->unsignedSmallInteger('adm_max');
            $table->uuid('initiator_user_id')->nullable();
            // Autoseed template the sweeps run with (NULL = instance default).
            $table->string('template', 48)->nullable();
            // Dashboard counters (denormalised; orchestrator updates them).
            $table->unsignedInteger('sized_parents')->default(0);
            $table->unsignedInteger('sized_leaves')->default(0);
            $table->unsignedInteger('singles_total')->default(0);
            $table->unsignedInteger('singles_done')->default(0);
            $table->unsignedInteger('sweeps_total')->default(0);
            $table->unsignedInteger('sweeps_done')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sizing_started_at')->nullable();
            $table->timestampTz('mapping_started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        Schema::create('autoscale_items', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('run_id')
                ->constrained('autoscale_runs')->cascadeOnDelete();
            $table->uuid('legislature_id');
            $table->uuid('jurisdiction_id');
            $table->unsignedSmallInteger('adm_level');
            // sweep (has children → mass mixed autoseed) | single (childless
            // leaf → one at-large district, handled set-based)
            $table->string('kind', 16);
            // pending | queued | running | done | review | halted | failed
            $table->string('status', 16)->default('pending');
            // Dispatch order (adm_level ASC, population DESC at enumeration —
            // Earth first, giants early). UUID ids don't sort by insertion.
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('seats_expected')->nullable();
            $table->unsignedInteger('seats_seated')->nullable();
            // Informational Σ-seat drift (seated − expected); never a failure.
            $table->integer('drift')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['run_id', 'status']);
            $table->index(['run_id', 'kind', 'status']);
            $table->index(['run_id', 'position']);
            // The sweep's DB heartbeat (publishMassProgress touches the
            // running item by legislature) must be an indexed no-op.
            $table->index(['legislature_id', 'status']);
            $table->unique(['run_id', 'legislature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autoscale_items');
        Schema::dropIfExists('autoscale_runs');
    }
};
