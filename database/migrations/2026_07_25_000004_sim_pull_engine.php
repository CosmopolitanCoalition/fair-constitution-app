<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The simulated-world pull engine — run / item / lease, mirroring the autoscale
 * engine's proven shapes rather than inventing a second orchestration idiom.
 *
 * The autoscale engine is the only planet-scale generator this codebase has
 * ever run to completion (521k sweeps + 903k singles), and its shape is the
 * distilled lesson of four failed runs: a pump as the sole liveness root,
 * SKIP-LOCKED single-unit claims, a DB halt flag, a pause-only pg breaker, and
 * a revert law. `docs/plans/etl/GEODATA_PULL_ENGINE_PLAN.md` already commits to
 * the same retrofit for the ETL. This is the third instance of one pattern.
 *
 * `sim_worker_leases` is deliberately BYTE-COMPATIBLE with
 * `autoscale_worker_leases` so the Step-3 live worker strip renders it with no
 * component changes — the same trick the geodata plan calls out.
 *
 * Design: docs/plans/simworld/SIM_SCALING_PLAN.md §7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sim_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // queued | running | done | halted | failed
            $table->string('status', 16)->default('queued');

            // enumerating → profiling → cohorts → identities → elections
            //            → counting → seating → verifying → done
            $table->string('phase', 24)->default('enumerating');

            $table->uuid('initiator_user_id')->nullable();

            // Run options: target set, tier, turnout dial, seed version, dry_run.
            $table->jsonb('options')->default('{}');

            // Denormalized counters, refreshed by the pump. The LIVE bars never
            // read these — they run a fresh GROUP BY per poll (the Step-3
            // pattern: real numbers every 2s, never a once-a-minute copy).
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_done')->default(0);
            $table->unsignedInteger('items_review')->default(0);
            $table->unsignedInteger('open_items')->default(0);

            // THE HALT FLAG — read per pump, per claim-loop iteration, and per
            // claimed item before work begins.
            $table->timestampTz('halt_requested_at')->nullable();

            // THE BREAKER — pause only, never a governor. A pg crash/recovery
            // moves stats_reset without a postmaster restart, so the
            // fingerprint is both values concatenated.
            $table->timestampTz('paused_until')->nullable();
            $table->text('pg_fingerprint')->nullable();

            // Per-phase started/finished stamps — this IS the benchmark report.
            $table->jsonb('phase_timings')->default('{}');

            $table->text('last_error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement(
            'ALTER TABLE sim_runs ADD CONSTRAINT sim_runs_status_check '
            ."CHECK (status IN ('queued','running','done','halted','failed'))"
        );

        Schema::create('sim_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('run_id')->constrained('sim_runs')->cascadeOnDelete();

            // manifest | profile_research | profile_inherit | cohort_scope
            // | identity_batch | election_scope | count_race | seat_scope
            // | acceptance_scan
            $table->string('kind', 24);

            // pending | running | done | review | failed
            $table->string('status', 16)->default('pending');

            $table->uuid('jurisdiction_id')->nullable();
            $table->uuid('legislature_id')->nullable();
            $table->uuid('race_id')->nullable();
            $table->unsignedSmallInteger('adm_level')->nullable();

            // THE IDEMPOTENCY KEY, and the reason it is a plain column rather
            // than a COALESCE expression over the id columns: not every unit
            // HAS a jurisdiction. `identity_batch` items are ordinal batches,
            // `manifest` and `acceptance_scan` are singletons. An expression key
            // over nullable ids collapses all of those into one unit and the
            // second batch collides. `unit_key` is whatever makes a unit unique
            // within its kind — a jurisdiction id, a race id, a batch ordinal,
            // or the literal kind for a singleton.
            $table->string('unit_key', 128);

            // Claim order. LARGEST-FIRST by est_cost (the geodata plan's
            // inversion of autoscale's simplest-first): there is no triage
            // benefit here, and the biggest populations must not define the tail.
            $table->unsignedInteger('position')->default(0);
            $table->bigInteger('est_cost')->default(0);

            $table->uuid('claim_token')->nullable();
            $table->text('reason')->nullable();

            // Per-item outcome: rows written, elapsed, seats seated, record hash.
            $table->jsonb('metrics')->default('{}');

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            // The claim index — also the live-bar feed (index-only GROUP BY).
            $table->index(['run_id', 'kind', 'status', 'position'], 'sim_items_claim_idx');
            $table->index(['run_id', 'status'], 'sim_items_run_status_idx');
            $table->index(['run_id', 'kind', 'adm_level', 'status'], 'sim_items_layers_idx');
        });

        DB::statement(
            'ALTER TABLE sim_items ADD CONSTRAINT sim_items_status_check '
            ."CHECK (status IN ('pending','running','done','review','failed'))"
        );

        // Re-minting a run's worklist after a crash uses
        // `ON CONFLICT ON CONSTRAINT sim_items_unit_uq DO NOTHING`, exactly as
        // `autoscale_scopes_scope_uq` does. It must be a real CONSTRAINT, not
        // merely a unique index: Postgres' `ON CONFLICT ON CONSTRAINT` resolves
        // constraint names only, and an expression index cannot be one.
        DB::statement(
            'ALTER TABLE sim_items ADD CONSTRAINT sim_items_unit_uq '
            .'UNIQUE (run_id, kind, unit_key)'
        );

        Schema::create('sim_worker_leases', function (Blueprint $table) {
            // id IS the claim token.
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('run_id');
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');
            $table->string('claim_type', 16)->nullable();
            $table->string('claim_label', 160)->nullable();
            $table->timestampTz('claim_started_at')->nullable();
            $table->string('lane', 16)->nullable();

            $table->index(['run_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_worker_leases');
        Schema::dropIfExists('sim_items');
        Schema::dropIfExists('sim_runs');
    }
};
