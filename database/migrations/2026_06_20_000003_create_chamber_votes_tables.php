<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-3 (PHASE_C_DESIGN_votes_laws §A) — THE VOTE ENGINE tables.
 *
 * Per-kind tallies live in a `chamber_vote_tallies` LANE table, not
 * columns: unicameral, committee, and each bicameral kind run identical
 * quorum+threshold math through ONE code path (one lane row shape);
 * BicameralDualAgreementTest pins row-level data; Phase D board votes
 * reuse the lane with zero migration.
 *
 * Thresholds are SNAPSHOTS resolved at open through the two PROTECTED
 * functions (ConstitutionalValidator::quorum / ::supermajority) — never
 * recomputed by readers, never reimplemented.
 *
 * `vote_casts` are PUBLIC member votes (Art. II §2 — the exact opposite
 * of `ballots`): value/rankings + optional explanation, each published to
 * public_records. Casts are immutable (no soft deletes, no updates): a
 * member may NOT change a cast — the record is the record.
 *
 * CONVENTION EXCEPTION: `chamber_votes` carries no soft deletes — vote
 * records are immutable once closed; corrections = a new vote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chamber_votes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 'board' fires Phase D.
            $table->string('body_type', 16);
            $table->uuid('body_id'); // polymorphic, no FK

            // Owning chamber, denormalized (set for committee votes too;
            // null for org boards).
            $table->uuid('legislature_id')->nullable();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            // bill, motion, emergency_power, renewal, referendum delegation,
            // appointment, removal_proceeding…; null for free-standing votes
            // (speaker RCV).
            $table->string('votable_type', 32)->nullable();
            $table->uuid('votable_id')->nullable();

            // Key into config/constitution/vote_types.php. NO DB CHECK —
            // documented exception: the registry is a code artifact, like
            // audit_log.form_id.
            $table->string('vote_type', 40);

            $table->string('vote_method', 8);

            // Snapshot resolved from vote_type (or the bill act_type
            // override) at open.
            $table->string('threshold_basis', 16);

            // Bills carry it — q-ledger #q7 applies at BOTH stages.
            $table->string('stage', 12)->nullable();

            // Snapshot: lanes = type_a + type_b vs a single 'all'.
            $table->boolean('bicameral')->default(false);

            // Σ lane serving at open.
            $table->smallInteger('serving_snapshot');

            // Floor votes; committee votes null in Phase C.
            $table->uuid('held_in_session_id')->nullable();
            $table->foreign('held_in_session_id')->references('id')->on('legislature_sessions')->nullOnDelete();

            $table->uuid('opened_by_member_id')->nullable();
            $table->foreign('opened_by_member_id')->references('id')->on('legislature_members')->nullOnDelete();

            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('closes_at')->nullable();
            $table->timestampTz('decided_at')->nullable();

            // 'tied' is terminal only if no tie-break is filed (F-SPK-004
            // re-closes the vote).
            $table->string('outcome', 12)->nullable();
            $table->boolean('speaker_tiebreak')->default(false);

            // Round-by-round record from VoteCountingService::countRcv over
            // PUBLIC rankings.
            $table->jsonb('rcv_record')->nullable();

            $table->string('status', 8)->default('open');

            $table->timestampsTz();
            // NO soft deletes — documented exception (immutable records).

            $table->index(['body_type', 'body_id']);
            $table->index(['votable_type', 'votable_id']);
            $table->index(['legislature_id', 'status']);
        });

        DB::statement('ALTER TABLE chamber_votes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE chamber_votes ADD CONSTRAINT chamber_votes_body_type_check CHECK (body_type IN ('legislature','committee','board'))");
        DB::statement("ALTER TABLE chamber_votes ADD CONSTRAINT chamber_votes_vote_method_check CHECK (vote_method IN ('yes_no','rcv'))");
        DB::statement("ALTER TABLE chamber_votes ADD CONSTRAINT chamber_votes_threshold_basis_check CHECK (threshold_basis IN ('majority','supermajority'))");
        DB::statement("ALTER TABLE chamber_votes ADD CONSTRAINT chamber_votes_stage_check CHECK (stage IS NULL OR stage IN ('committee','floor'))");
        DB::statement("ALTER TABLE chamber_votes ADD CONSTRAINT chamber_votes_outcome_check CHECK (outcome IS NULL OR outcome IN ('adopted','failed','tied'))");
        DB::statement("ALTER TABLE chamber_votes ADD CONSTRAINT chamber_votes_status_check CHECK (status IN ('open','closed','void'))");

        Schema::create('chamber_vote_tallies', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('vote_id');
            $table->foreign('vote_id')->references('id')->on('chamber_votes')->cascadeOnDelete();

            // 'all' (unicameral/committee) | 'type_a' | 'type_b' (bicameral
            // = exactly two rows).
            $table->string('lane', 8);

            $table->smallInteger('serving');
            $table->smallInteger('quorum_required');   // quorum(serving) snapshot
            $table->smallInteger('required_yes');      // majority ⇒ quorum; supermajority ⇒ supermajority(serving, n, d)

            $table->smallInteger('present')->nullable(); // set at close (§B presence rule)

            $table->smallInteger('yes')->default(0);
            $table->smallInteger('no')->default(0);
            $table->smallInteger('abstain')->default(0);

            $table->boolean('quorate')->nullable();
            $table->boolean('passed')->nullable();

            $table->unique(['vote_id', 'lane']);
        });

        DB::statement('ALTER TABLE chamber_vote_tallies ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE chamber_vote_tallies ADD CONSTRAINT chamber_vote_tallies_lane_check CHECK (lane IN ('all','type_a','type_b'))");
        DB::statement('ALTER TABLE chamber_vote_tallies ADD CONSTRAINT chamber_vote_tallies_counts_check CHECK (yes + no + abstain <= serving)');

        Schema::create('vote_casts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('vote_id');
            $table->foreign('vote_id')->references('id')->on('chamber_votes')->cascadeOnDelete();

            $table->uuid('member_id');
            $table->foreign('member_id')->references('id')->on('legislature_members')->restrictOnDelete();

            // Snapshot of the member's seat kind at cast.
            $table->string('lane', 8);

            // null for rcv casts (CHECK below: exactly one of value/rankings).
            $table->string('value', 8)->nullable();

            // RCV ballots — PUBLIC and published (Art. II §2 member votes).
            $table->jsonb('rankings')->nullable();

            $table->boolean('is_tiebreak')->default(false); // F-SPK-004

            // Published with the cast.
            $table->text('explanation')->nullable();

            // F-LEG-004 / F-LEG-005 / F-LEG-008 / F-LEG-011 / F-SPK-004.
            $table->string('cast_via_form', 12);

            // Every cast publishes a public_records row kind 'vote'.
            $table->uuid('public_record_id')->nullable();

            $table->timestampTz('cast_at')->useCurrent();

            // No soft deletes, no updated_at — casts are immutable.
            $table->unique(['vote_id', 'member_id']);
            $table->index('member_id');
        });

        DB::statement('ALTER TABLE vote_casts ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE vote_casts ADD CONSTRAINT vote_casts_lane_check CHECK (lane IN ('all','type_a','type_b'))");
        DB::statement("ALTER TABLE vote_casts ADD CONSTRAINT vote_casts_value_check CHECK (value IS NULL OR value IN ('yes','no','abstain'))");
        DB::statement('ALTER TABLE vote_casts ADD CONSTRAINT vote_casts_value_xor_rankings CHECK ((value IS NOT NULL) <> (rankings IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX vote_casts_one_tiebreak ON vote_casts (vote_id) WHERE is_tiebreak');
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_casts');
        Schema::dropIfExists('chamber_vote_tallies');
        Schema::dropIfExists('chamber_votes');
    }
};
