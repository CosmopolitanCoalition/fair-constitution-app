<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-8 — `tabulations` + `tabulation_rounds` + `race_results`
 * (PHASE_B_DESIGN_schema_lifecycle §A B-8).
 *
 * `tabulations`: one count run per race. `kind = 'countback'` requires
 * `excluded_candidacy_id` (the countback strike — universal full re-run
 * minus the vacating candidacy, NOT NULL iff countback, DB CHECK).
 * `engine_version` snapshots VoteCountingService::VERSION; `record_hash`
 * is sha256 of the canonical full round record, sealed into the audit
 * chain on completion.
 *
 * `tabulation_rounds.transfer` mirrors the mockup STV_DATA.display[]
 * contract exactly ({kind: 'surplus'|'elimination', value,
 * to: [[candidacy_id, votes]], exhausted} — Gregory fractional) so
 * Results.vue lifts straight from results.html.
 *
 * `race_results.vote_share_norm` is the normalized-quota share — committee
 * tie-break input (q-ledger #2); computed once here, copied to the member
 * row at seating. `runner_up_rank` (1–4 sequential-exclusion advisors) is
 * schema-now, written by Phase D exec races.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── tabulations ─────────────────────────────────────────────────────
        Schema::create('tabulations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('race_id');
            $table->foreign('race_id')->references('id')->on('election_races')->cascadeOnDelete();

            $table->string('kind', 12)->default('initial');

            // The countback strike.
            $table->uuid('excluded_candidacy_id')->nullable();
            $table->foreign('excluded_candidacy_id')->references('id')->on('candidacies')->restrictOnDelete();

            $table->string('engine_version', 16);

            $table->integer('total_valid')->nullable();
            $table->integer('quota')->nullable();
            $table->smallInteger('seats');

            $table->string('status', 12)->default('running');

            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            // sha256 of the canonical full round record.
            $table->char('record_hash', 64)->nullable();

            $table->timestampsTz();

            $table->index(['race_id', 'kind', 'status']);
        });

        DB::statement('ALTER TABLE tabulations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE tabulations ADD CONSTRAINT tabulations_kind_check " .
            "CHECK (kind IN ('initial', 'audit_rerun', 'countback'))"
        );
        DB::statement(
            "ALTER TABLE tabulations ADD CONSTRAINT tabulations_status_check " .
            "CHECK (status IN ('running', 'complete', 'superseded'))"
        );
        // excluded_candidacy_id NOT NULL iff kind = 'countback'.
        DB::statement(
            "ALTER TABLE tabulations ADD CONSTRAINT tabulations_countback_exclusion_check CHECK (" .
            "(kind = 'countback' AND excluded_candidacy_id IS NOT NULL) OR " .
            "(kind <> 'countback' AND excluded_candidacy_id IS NULL))"
        );

        // ── tabulation_rounds ───────────────────────────────────────────────
        Schema::create('tabulation_rounds', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tabulation_id');
            $table->foreign('tabulation_id')->references('id')->on('tabulations')->cascadeOnDelete();

            $table->smallInteger('round_no');

            $table->string('action', 12);

            $table->uuid('candidacy_id');
            $table->foreign('candidacy_id')->references('id')->on('candidacies')->restrictOnDelete();

            // STV_DATA.display[] transfer contract (see docblock); NULL when
            // the round has no transfer (e.g. final-seat election).
            $table->jsonb('transfer')->nullable();

            $table->jsonb('tallies')->default('{}');

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tabulation_id', 'round_no']);
        });

        DB::statement('ALTER TABLE tabulation_rounds ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE tabulation_rounds ADD CONSTRAINT tabulation_rounds_action_check " .
            "CHECK (action IN ('elect', 'eliminate'))"
        );

        // ── race_results ────────────────────────────────────────────────────
        Schema::create('race_results', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tabulation_id');
            $table->foreign('tabulation_id')->references('id')->on('tabulations')->cascadeOnDelete();

            $table->uuid('candidacy_id');
            $table->foreign('candidacy_id')->references('id')->on('candidacies')->restrictOnDelete();

            $table->smallInteger('round_elected')->nullable();
            $table->smallInteger('seat_no')->nullable();

            // Normalized-quota share — committee tie-break input.
            $table->decimal('vote_share_norm', 8, 4)->nullable();

            // 1–4 sequential-exclusion advisors (Phase D exec races).
            $table->boolean('is_runner_up')->default(false);
            $table->smallInteger('runner_up_rank')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tabulation_id', 'candidacy_id']);
        });

        DB::statement('ALTER TABLE race_results ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('race_results');
        Schema::dropIfExists('tabulation_rounds');
        Schema::dropIfExists('tabulations');
    }
};
