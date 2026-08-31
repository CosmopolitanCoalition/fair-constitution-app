<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE LEDGER SINGLE HOME (operator ruling 2026-08-31): one run, facts
 * written once, no duplicate copies. The apportionment ledger becomes the
 * single home for facts AND work state — headers absorb the item columns,
 * ledger scopes absorb the scope work columns, and autoscale_items /
 * autoscale_scopes retire. compute_status is the phase-2 walk worklist;
 * map_status is the phase-4 drawing state. world_builds is the phase-2
 * progress record the accept gate verifies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apportionment_ledger', function (Blueprint $t) {
            $t->renameColumn('status', 'compute_status');
        });

        Schema::table('apportionment_ledger', function (Blueprint $t) {
            // Facts (written once at world build).
            $t->unsignedSmallInteger('adm_level')->nullable();
            $t->string('kind', 16)->nullable();
            $t->integer('child_count')->nullable();
            $t->smallInteger('est_districts')->nullable();
            $t->smallInteger('cascade_height')->nullable();
            $t->smallInteger('area_tier')->nullable();
            $t->unsignedInteger('position')->nullable();
            $t->integer('block_rank')->nullable();
            $t->bigInteger('block_order')->nullable();
            $t->uuid('map_id')->nullable();
            // Work state (cleared per benchmark).
            $t->string('map_status', 16)->default('pending');
            $t->integer('seats_seated')->nullable();
            $t->integer('drift')->nullable();
            $t->text('reason')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampTz('priority_at')->nullable();
            $t->timestampTz('redraw_requested_at')->nullable();
            $t->smallInteger('transient_retries')->default(0);

            $t->index(['kind', 'map_status'], 'al_kind_status_idx');
            $t->index(['adm_level', 'kind'], 'al_level_kind_idx');
            $t->index('position', 'al_position_idx');
            $t->index(['map_status', 'finished_at'], 'al_status_finished_idx');
        });
        DB::statement("CREATE INDEX al_block_gate_idx ON apportionment_ledger (block_rank)
                        WHERE map_status IN ('pending','running') AND block_rank IS NOT NULL");
        DB::statement('CREATE INDEX al_priority_idx ON apportionment_ledger (priority_at) WHERE priority_at IS NOT NULL');
        DB::statement('CREATE INDEX al_claim_idx ON apportionment_ledger (claim_token) WHERE claim_token IS NOT NULL');

        Schema::table('apportionment_ledger_scopes', function (Blueprint $t) {
            $t->uuid('id')->default(DB::raw('gen_random_uuid()'))->unique();
            $t->string('status', 16)->default('pending');
            $t->uuid('claim_token')->nullable();
            $t->text('reason')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->smallInteger('retry_count')->default(0);
            $t->jsonb('step_timings')->nullable();
            $t->smallInteger('area_tier')->nullable();
            $t->integer('reverse_position')->nullable();

            $t->index('status', 'als_status_idx');
            $t->index(['legislature_id', 'status'], 'als_leg_status_idx');
            $t->index(['status', 'walk_position'], 'als_status_walk_idx');
            $t->index(['status', 'finished_at'], 'als_status_finished_idx');
        });
        // Timestamps with DB defaults so set-based inserts stay legal, and
        // updated_at IS the reclaim clock the heartbeat writes.
        DB::statement('ALTER TABLE apportionment_ledger_scopes ADD COLUMN created_at timestamptz NOT NULL DEFAULT now()');
        DB::statement('ALTER TABLE apportionment_ledger_scopes ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now()');
        // NULL = unstamped (the repair sentinel).
        DB::statement('ALTER TABLE apportionment_ledger_scopes ALTER COLUMN walk_position DROP NOT NULL');
        DB::statement('ALTER TABLE apportionment_ledger_scopes ALTER COLUMN seat_budget DROP NOT NULL');
        DB::statement('CREATE INDEX als_claim_idx ON apportionment_ledger_scopes (claim_token) WHERE claim_token IS NOT NULL');

        Schema::create('world_builds', function (Blueprint $t) {
            $t->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $t->uuid('geodata_run_id')->nullable();
            $t->string('status', 16)->default('building');
            $t->jsonb('steps')->nullable();
            $t->timestampTz('lease_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_builds');
        DB::statement('DROP INDEX IF EXISTS als_claim_idx');
        Schema::table('apportionment_ledger_scopes', function (Blueprint $t) {
            $t->dropColumn([
                'id', 'status', 'claim_token', 'reason', 'started_at', 'finished_at',
                'retry_count', 'step_timings', 'area_tier', 'reverse_position',
                'created_at', 'updated_at',
            ]);
        });
        DB::statement('DROP INDEX IF EXISTS al_block_gate_idx');
        DB::statement('DROP INDEX IF EXISTS al_priority_idx');
        DB::statement('DROP INDEX IF EXISTS al_claim_idx');
        Schema::table('apportionment_ledger', function (Blueprint $t) {
            $t->dropColumn([
                'adm_level', 'kind', 'child_count', 'est_districts', 'cascade_height',
                'area_tier', 'position', 'block_rank', 'block_order', 'map_id',
                'map_status', 'seats_seated', 'drift', 'reason', 'started_at',
                'finished_at', 'priority_at', 'redraw_requested_at', 'transient_retries',
            ]);
        });
        Schema::table('apportionment_ledger', function (Blueprint $t) {
            $t->renameColumn('compute_status', 'status');
        });
    }
};
