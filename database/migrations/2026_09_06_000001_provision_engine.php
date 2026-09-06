<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE STEP 4 ENGINE (Wave 6, operator rulings 2026-09-05): the districting
 * posture applied to institutions. One ledger row per legislature; lanes
 * claim from it; a pump owns liveness; halt, resume and rollback are
 * operator controls. Additive, REAL-dated (post-flatten law).
 *
 *   provision_runs           the phase and clock record (one live run)
 *   provision_ledger         one row per legislature: cost, walk position,
 *                            stage (0 shells pending, 1 shells done, 2 done),
 *                            work state and the manifest of what the unit
 *                            minted (the rollback reads it)
 *   provision_worker_leases  one row per live lane (heartbeat + backend pid)
 *
 * Also lands the live-unique index on legislatures(jurisdiction_id) (ruling
 * dup-legislatures A): the parent seeding read-then-inserted, and the
 * 2026-08-29 parallel sizing pass wrote two rows for two jurisdictions.
 * Exact duplicates that hold no dependent rows are soft-deleted here first;
 * the index then makes the race impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status', 16)->default('queued');   // queued|running|halted|done|failed
            $t->timestampTz('halt_requested_at')->nullable();
            $t->timestampTz('paused_until')->nullable();
            $t->timestampTz('ledger_seeded_at')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampTz('rolled_back_at')->nullable();
            $t->integer('ledger_total')->default(0);
            $t->integer('ledger_skipped')->default(0);
            $t->integer('shells_done')->default(0);
            $t->integer('units_done')->default(0);
            $t->integer('review_count')->default(0);
            $t->text('last_error')->nullable();
            $t->jsonb('baseline')->nullable();               // measured durations, never assumed
            $t->timestampsTz();
            $t->index('status');
        });

        Schema::create('provision_ledger', function (Blueprint $t) {
            $t->uuid('legislature_id')->primary();
            $t->uuid('jurisdiction_id');
            $t->integer('est_cost')->default(0);
            $t->smallInteger('stage')->default(0);           // 0 shells pending · 1 shells done · 2 done
            $t->string('status', 12)->default('pending');    // pending|running|done|review|skipped
            $t->uuid('claim_token')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->smallInteger('retry_count')->default(0);
            $t->text('reason')->nullable();
            $t->jsonb('manifest')->nullable();               // what the unit minted: election, committees, departments
            $t->timestampTz('updated_at')->nullable();
            $t->index(['status', 'stage', 'est_cost', 'legislature_id'], 'pl_claim_idx');
            $t->index('claim_token', 'pl_claim_token_idx');
            $t->index('jurisdiction_id', 'pl_jurisdiction_idx');
        });

        Schema::create('provision_worker_leases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('run_id');
            $t->string('lane', 12)->default('topdown');
            $t->integer('pg_backend_pid')->nullable();
            $t->string('claim_type', 16)->nullable();
            $t->string('claim_label', 200)->nullable();
            $t->timestampTz('claim_started_at')->nullable();
            $t->uuid('current_legislature_id')->nullable();
            $t->integer('claims_done')->default(0);
            $t->timestampTz('started_at');
            $t->timestampTz('last_seen_at');
            $t->index(['run_id', 'last_seen_at']);
        });

        // ── The live-unique legislature (ruling dup-legislatures A) ─────────
        // A jurisdiction holds ONE live legislature. Where the 2026-08-29
        // parallel sizing pass minted two (Githunguri, Kalmar — each row with
        // its own header, map and districts), the flukes are deleted (operator
        // rule: "if a fluke delete the flukes"). The winner is the row with
        // seated members or elections, then the oldest, then the lowest id;
        // every loser's drawn output (ledger header + scopes, Type B
        // groupings, maps, districts, memberships, subdivisions) goes with it
        // and the row soft-deletes. A loser that holds members or elections is
        // never touched: that is a world, not a fluke, and the index refuses.
        DB::statement('CREATE TEMP TABLE legislature_dupes ON COMMIT DROP AS
            WITH ranked AS (
                SELECT l.id,
                       ROW_NUMBER() OVER (
                           PARTITION BY l.jurisdiction_id
                           ORDER BY (EXISTS (SELECT 1 FROM legislature_members lm WHERE lm.legislature_id = l.id)) DESC,
                                    (EXISTS (SELECT 1 FROM elections e WHERE e.legislature_id = l.id)) DESC,
                                    l.created_at ASC, l.id ASC
                       ) AS rn
                  FROM legislatures l
                 WHERE l.deleted_at IS NULL
            )
            SELECT id FROM ranked
             WHERE rn > 1
               AND NOT EXISTS (SELECT 1 FROM legislature_members lm WHERE lm.legislature_id = ranked.id)
               AND NOT EXISTS (SELECT 1 FROM elections e WHERE e.legislature_id = ranked.id)');

        DB::statement('DELETE FROM apportionment_ledger_scopes s USING legislature_dupes d WHERE s.legislature_id = d.id');
        DB::statement('DELETE FROM apportionment_ledger h USING legislature_dupes d WHERE h.legislature_id = d.id');
        DB::statement('DELETE FROM legislature_type_b_panel_jurisdictions pj
                        USING legislature_type_b_panels p, legislature_type_b_groupings g, legislature_dupes d
                        WHERE pj.panel_id = p.id AND p.grouping_id = g.id AND g.legislature_id = d.id');
        DB::statement('DELETE FROM legislature_type_b_panels p USING legislature_type_b_groupings g, legislature_dupes d
                        WHERE p.grouping_id = g.id AND g.legislature_id = d.id');
        DB::statement('DELETE FROM legislature_type_b_groupings g USING legislature_dupes d WHERE g.legislature_id = d.id');
        DB::statement('DELETE FROM district_subdivisions ds USING legislature_district_maps m, legislature_dupes d
                        WHERE ds.map_id = m.id AND m.legislature_id = d.id');
        DB::statement('DELETE FROM legislature_district_jurisdictions ldj USING legislature_districts x, legislature_dupes d
                        WHERE ldj.district_id = x.id AND x.legislature_id = d.id');
        DB::statement('DELETE FROM legislature_districts x USING legislature_dupes d WHERE x.legislature_id = d.id');
        DB::statement('DELETE FROM legislature_district_maps m USING legislature_dupes d WHERE m.legislature_id = d.id');
        DB::statement('UPDATE legislatures l SET deleted_at = now(), updated_at = now() FROM legislature_dupes d WHERE l.id = d.id');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS legislatures_live_jurisdiction_uq
                       ON legislatures (jurisdiction_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS legislatures_live_jurisdiction_uq');
        Schema::dropIfExists('provision_worker_leases');
        Schema::dropIfExists('provision_ledger');
        Schema::dropIfExists('provision_runs');
    }
};
