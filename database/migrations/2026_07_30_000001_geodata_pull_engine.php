<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geodata pull engine (GEODATA_PULL_ENGINE_PLAN.md, 2026-07-20) — additive on
 * top of the flattened baseline.
 *
 * Retrofits the autoscale pull-engine shape (claim ladder → worker pool → pump
 * liveness → review/requeue → one final acceptance gate) onto the geodata ETL,
 * so ingestion becomes multithreaded, incrementally reprocessable, and visible
 * per-worker. Three tables mirror autoscale's proven shapes:
 *
 *  - geodata_runs: one ETL run. status (running|halted|failed|done) + phase
 *    (the pipeline position that gates which item KIND is claimable) + the
 *    DB halt flag + the pg-crash breaker + per-phase timestamps (the benchmark
 *    instrumentation) + aggregate counters the pump refreshes.
 *  - geodata_items: the claimable work unit (one ISO's boundary chain, one
 *    raster load, one attribution pair, or a barrier singleton). position is
 *    the largest-first ordering key (est_cost DESC, assigned at enumeration).
 *  - geodata_worker_leases: pump-visible worker liveness + the per-worker claim
 *    strip — byte-compatible with the autoscale lease display so the Step-2 UI
 *    reuses the Step-3 worker-strip component.
 *
 * progress.json is retired for pull-engine runs (the DB is the state — that is
 * what makes halt/resume/requeue and the UI trivial). The legacy
 * seed_database.py CLI path keeps progress.json untouched for bare-metal use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geodata_runs', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            // Run lifecycle. phase (below) is the pipeline position; status is
            // whether the run as a whole is live/parked/terminal.
            $table->string('status', 16)->default('running');
            // enumerating → boundaries → resolving → rasters → attribution →
            // finalizing → scanning → done. The pump advances it; a worker
            // only claims items whose kind matches the CURRENT phase, so the
            // enum IS the barrier between fan-out phases.
            $table->string('phase', 16)->default('enumerating');
            $table->text('data_root')->nullable();
            // countries filter, adm_levels, fresh, dry_run, source, download opts.
            $table->jsonb('options')->nullable();

            // Aggregate counters (per-kind detail is a live GROUP BY in the
            // progress endpoint; these are the quick run-level headline).
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_done')->default(0);
            $table->unsignedInteger('items_review')->default(0);
            $table->unsignedInteger('items_failed')->default(0);

            // Operator halt — DB-backed (workers poll this at claim boundaries).
            $table->timestampTz('halt_requested_at')->nullable();
            // pg-crash breaker: claims pause until this passes. Fingerprint =
            // postmaster start time || stats_reset (parity with autoscale).
            $table->timestampTz('paused_until')->nullable();
            $table->text('pg_fingerprint')->nullable();

            // Per-phase started/finished stamps — the benchmark report IS this
            // column (§9). { "boundaries": {"started_at":…,"finished_at":…}, … }.
            $table->jsonb('phase_timestamps')->nullable();

            $table->uuid('initiator_user_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        Schema::create('geodata_items', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('run_id')
                ->constrained('geodata_runs')->cascadeOnDelete();
            // manifest | boundary_iso | resolve_global | raster_iso |
            // attribution_pair | finalize_global | acceptance_scan
            $table->string('kind', 24);
            $table->string('iso_code', 8)->nullable();
            $table->smallInteger('adm_level')->nullable();
            // pending | running | done | review | failed
            $table->string('status', 16)->default('pending');
            $table->uuid('claim_token')->nullable();
            $table->text('reason')->nullable();
            // Largest-first ordering key (est_cost DESC rank, assigned at
            // enumeration): claim ORDER BY position ASC starts the heaviest
            // unit first so the straggler (IND L6, 649k polys) defines the tail
            // alone and never lands last.
            $table->bigInteger('position')->default(0);
            $table->bigInteger('est_cost')->default(0);
            // T.7 review-then-APPLY: a requeued pair may run dry (metrics + a
            // flag row, no UPDATEs). The founding seed applies directly.
            $table->boolean('dry_run')->default(false);
            // rows inserted, tiles, n_polys, elapsed, pre/post sums for pairs.
            $table->jsonb('metrics')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['run_id', 'kind', 'status']);
        });

        // Partial claim index (schema builder has no partial-index support).
        DB::statement("
            CREATE INDEX geodata_items_claim_idx
                ON geodata_items (run_id, position)
             WHERE status = 'pending'
        ");

        Schema::create('geodata_worker_leases', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->uuid('run_id');
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');
            // Per-worker claim strip (parity with autoscale_worker_leases):
            // claim_type e.g. 'boundary_iso', claim_label e.g. 'boundaries · IND'.
            $table->string('claim_type', 24)->nullable();
            $table->text('claim_label')->nullable();
            $table->timestampTz('claim_started_at')->nullable();

            $table->index(['run_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geodata_worker_leases');
        Schema::dropIfExists('geodata_items');
        Schema::dropIfExists('geodata_runs');
    }
};
