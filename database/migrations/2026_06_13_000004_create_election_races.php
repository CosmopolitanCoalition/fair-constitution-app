<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-4 — `election_races`: the race-level slice of ESM-03
 * (PHASE_B_DESIGN_schema_lifecycle §A B-4).
 *
 * One row per contest: `district_id` NULL = at-large race (constitutional
 * default when seats ≤ 9 with no map — Art. II §8 forbids subdivision
 * unless seats exceed the max). `seat_kind` carries bicameral dual-kind
 * races; `single` is reserved for the individual executive (Phase D).
 *
 * `finalist_count` is X = finalist_multiplier × seats (CLK-21), FROZEN here
 * at race creation and pre-published with the scheduling order — later
 * `finalist_multiplier` amendments never move a published cutoff.
 *
 * The seats CHECK is 1–9 (hard ceiling); the 5–9 floor for chamber races
 * (and exactly 1 for `single`) is an ENGINE rule, not a CHECK — see
 * ConstitutionalValidator `elections.race_structure` (WI-B4).
 *
 * `status` mirrors the parent ESM-03 states (per-race close/tabulate can
 * stagger). `quota` is the Droop snapshot set at tabulation:
 * floor(valid/(seats+1))+1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('election_races', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('election_id');
            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();

            // NULL = at-large race.
            $table->uuid('district_id')->nullable();
            $table->foreign('district_id')->references('id')->on('legislature_districts')->restrictOnDelete();

            // Race footprint (district's parent scope, or the legislature's
            // jurisdiction for at-large).
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            $table->string('seat_kind', 8);
            $table->smallInteger('seats');

            // CLK-21: X frozen at race creation, pre-published.
            $table->smallInteger('finalist_count');

            // owners/workers = Phase D org-board reuse.
            $table->string('electorate_type', 12)->default('residents');

            // Droop snapshot, set at tabulation.
            $table->integer('quota')->nullable();
            $table->integer('total_valid_ballots')->nullable();

            $table->string('status', 16)->default('scheduled');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['election_id', 'district_id', 'seat_kind']);
            $table->index(['election_id', 'status']);
            $table->index('district_id');
        });

        DB::statement('ALTER TABLE election_races ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            "ALTER TABLE election_races ADD CONSTRAINT election_races_seat_kind_check " .
            "CHECK (seat_kind IN ('type_a', 'type_b', 'single'))"
        );
        DB::statement(
            'ALTER TABLE election_races ADD CONSTRAINT election_races_seats_check ' .
            'CHECK (seats BETWEEN 1 AND 9)'
        );
        DB::statement(
            "ALTER TABLE election_races ADD CONSTRAINT election_races_electorate_type_check " .
            "CHECK (electorate_type IN ('residents', 'owners', 'workers'))"
        );
        DB::statement(
            "ALTER TABLE election_races ADD CONSTRAINT election_races_status_check CHECK (status IN (" .
            "'scheduled', 'approval_open', 'finalist_cutoff', 'ranked_open', 'voting_closed', " .
            "'tabulating', 'certified', 'audit_rerun', 'final', 'cancelled'))"
        );

        // Postgres treats NULL district_id as distinct in the composite
        // unique — cap one at-large race per seat kind explicitly.
        DB::statement(
            'CREATE UNIQUE INDEX election_races_one_at_large_per_kind ON election_races ' .
            '(election_id, seat_kind) WHERE district_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('election_races');
    }
};
