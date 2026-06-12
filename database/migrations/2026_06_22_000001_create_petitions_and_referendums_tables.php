<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-8 (PHASE_C_DESIGN_votes_laws §A) — petitions (ESM-10) +
 * petition_signatures + referendum_questions (ESM-11), plus the
 * Phase-B-anticipated ballot-table evolution for question-scoped voting
 * (the 2026_06_13_000007 migration's comment "extended with question id
 * in Phase C" lands here).
 *
 * Population-denominator decision (flagged, q-ledger candidate): every
 * "population" threshold (petition CLK-17, referendum majority /
 * supermajority) uses the CIVIC population — count of active
 * residency_confirmations rows for the jurisdiction — never WorldPop
 * `jurisdictions.population`. Real population stays provenance data.
 *
 * Ballot evolution (Art. II §2 secrecy posture unchanged):
 *  - ballot_envelopes.race_id → nullable; kind-pairing CHECK; the single
 *    unique is replaced by two partial uniques (ranked by race,
 *    referendum by question);
 *  - ballots.race_id → nullable; referendum_question_id added with the
 *    mirror CHECK + (question, counted) index. A question id in clear on
 *    an anonymous ballot leaks nothing (no voter linkage); the yes/no
 *    choice stays inside payload_encrypted under the election's wrapped
 *    key (same BallotCrypto path). BallotSecrecyTest's exact column-list
 *    pin is updated in the same batch.
 *
 * Also extends chamber_vote_proposals' kind CHECK with this batch's
 * proposal kinds (referendum_delegation, referendum_act_modification,
 * emergency_invocation, emergency_renewal).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── petitions — ESM-10 ──────────────────────────────────────────────
        Schema::create('petitions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('creator_user_id');
            $table->foreign('creator_user_id')->references('id')->on('users')->restrictOnDelete();

            // Scale anchor.
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->string('title');

            // The binding text voters ratify.
            $table->text('law_text');

            // No dual_supermajority by petition; drives the referendum threshold.
            $table->string('act_type', 20);

            // Same CHECK pairing as bills; bounds-validated at creation.
            $table->string('targets_setting_key')->nullable();
            $table->jsonb('proposed_value')->nullable();

            $table->jsonb('scale');

            $table->uuid('scope_judiciary_id')->nullable();
            $table->foreign('scope_judiciary_id')->references('id')->on('judiciaries')->nullOnDelete();

            // CIVIC population snapshot at creation (see file docblock).
            $table->integer('population_basis');

            // initiative_petition_threshold_pct snapshot at creation (CLK-17).
            $table->decimal('threshold_pct', 5, 2);

            // ceil(population_basis × threshold_pct / 100).
            $table->integer('threshold_count');

            $table->string('status', 24)->default('created');

            // F-ELB-005 output {checked, valid, invalid, invalid_reasons{}, pct}.
            $table->jsonb('audit_result')->nullable();

            // Phase E (F-JDG-008) — no FK, the institution does not exist yet.
            $table->uuid('review_case_id')->nullable();

            // Phase C constitutional-review stub marker (votes_laws §E).
            $table->boolean('review_stub')->default(false);

            // FK added below (referendum_questions created later in this file).
            $table->uuid('referendum_question_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['jurisdiction_id', 'status']);
        });

        DB::statement('ALTER TABLE petitions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE petitions ADD CONSTRAINT petitions_act_type_check
            CHECK (act_type IN ('ordinary','setting_change','supermajority'))
        ");
        DB::statement("
            ALTER TABLE petitions ADD CONSTRAINT petitions_status_check
            CHECK (status IN ('created','gathering','threshold_reached','signature_audit',
                              'constitutional_review','validated','on_ballot','adopted',
                              'rejected','invalidated'))
        ");
        DB::statement("
            ALTER TABLE petitions ADD CONSTRAINT petitions_setting_pairing_check
            CHECK ((act_type = 'setting_change') = (targets_setting_key IS NOT NULL))
        ");

        // ── petition_signatures ─────────────────────────────────────────────
        Schema::create('petition_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('petition_id');
            $table->foreign('petition_id')->references('id')->on('petitions')->cascadeOnDelete();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            // Provenance: the residency_confirmations row live at signing.
            $table->uuid('association_id')->nullable();

            $table->timestampTz('signed_at');
            $table->timestampTz('revoked_at')->nullable();

            $table->index('user_id');
        });

        DB::statement('ALTER TABLE petition_signatures ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        // Revocable while gathering: one LIVE signature per user per petition.
        DB::statement('
            CREATE UNIQUE INDEX petition_signatures_one_live
            ON petition_signatures (petition_id, user_id)
            WHERE revoked_at IS NULL
        ');

        // ── referendum_questions — ESM-11 ───────────────────────────────────
        Schema::create('referendum_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->string('origin', 12);

            // Refines DESIGN A.3's delegating_law_id: the F-LEG-023
            // delegation is a supermajority RESOLUTION (a chamber_vote),
            // not a statute — the law is what the referendum enacts.
            $table->uuid('delegating_vote_id')->nullable();
            $table->foreign('delegating_vote_id')->references('id')->on('chamber_votes')->restrictOnDelete();

            $table->uuid('petition_id')->nullable();
            $table->foreign('petition_id')->references('id')->on('petitions')->restrictOnDelete();

            // Ballot text + the text that becomes law v1 on passage.
            $table->text('question');
            $table->text('law_text');

            $table->string('act_type', 20);

            // DERIVED from act_type — never editable, no API accepts it.
            $table->string('threshold', 16);

            $table->string('targets_setting_key')->nullable();
            $table->jsonb('proposed_value')->nullable();

            // Rides the NEXT jurisdiction-wide ballot.
            $table->uuid('election_id')->nullable();
            $table->foreign('election_id')->references('id')->on('elections')->restrictOnDelete();

            // Civic-population snapshot at voting close (the peg denominator).
            $table->integer('eligible_population')->nullable();

            $table->integer('yes_count')->nullable();
            $table->integer('no_count')->nullable();

            $table->string('status', 12)->default('queued');

            $table->uuid('resulting_law_id')->nullable();
            $table->foreign('resulting_law_id')->references('id')->on('laws')->restrictOnDelete();

            $table->timestampTz('certified_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['jurisdiction_id', 'status']);
            $table->index(['election_id', 'status']);
        });

        DB::statement('ALTER TABLE referendum_questions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE referendum_questions ADD CONSTRAINT referendum_questions_origin_check
            CHECK (origin IN ('delegation','petition'))
        ");
        DB::statement("
            ALTER TABLE referendum_questions ADD CONSTRAINT referendum_questions_act_type_check
            CHECK (act_type IN ('ordinary','setting_change','supermajority'))
        ");
        DB::statement("
            ALTER TABLE referendum_questions ADD CONSTRAINT referendum_questions_status_check
            CHECK (status IN ('queued','scheduled','voted','passed','failed','invalidated'))
        ");
        DB::statement("
            ALTER TABLE referendum_questions ADD CONSTRAINT referendum_questions_threshold_check
            CHECK (threshold IN ('majority','supermajority')
                   AND ((act_type = 'supermajority') = (threshold = 'supermajority')))
        ");
        DB::statement("
            ALTER TABLE referendum_questions ADD CONSTRAINT referendum_questions_one_origin_check
            CHECK ((origin = 'delegation' AND delegating_vote_id IS NOT NULL AND petition_id IS NULL)
                OR (origin = 'petition'   AND petition_id IS NOT NULL AND delegating_vote_id IS NULL))
        ");
        DB::statement("
            ALTER TABLE referendum_questions ADD CONSTRAINT referendum_questions_setting_pairing_check
            CHECK ((act_type = 'setting_change') = (targets_setting_key IS NOT NULL))
        ");

        // ── Close the deferred FKs ──────────────────────────────────────────
        DB::statement('
            ALTER TABLE petitions ADD CONSTRAINT petitions_referendum_question_id_foreign
            FOREIGN KEY (referendum_question_id) REFERENCES referendum_questions(id) ON DELETE SET NULL
        ');
        DB::statement('
            ALTER TABLE ballot_envelopes ADD CONSTRAINT ballot_envelopes_referendum_question_id_foreign
            FOREIGN KEY (referendum_question_id) REFERENCES referendum_questions(id) ON DELETE RESTRICT
        ');

        // ── Phase B ballot tables: question-scoped voting ───────────────────
        DB::statement('ALTER TABLE ballot_envelopes ALTER COLUMN race_id DROP NOT NULL');
        DB::statement("
            ALTER TABLE ballot_envelopes ADD CONSTRAINT ballot_envelopes_kind_pairing_check
            CHECK ((kind = 'ranked'     AND race_id IS NOT NULL AND referendum_question_id IS NULL)
                OR (kind = 'referendum' AND referendum_question_id IS NOT NULL AND race_id IS NULL))
        ");
        DB::statement('ALTER TABLE ballot_envelopes DROP CONSTRAINT IF EXISTS ballot_envelopes_race_id_user_id_kind_unique');
        DB::statement("
            CREATE UNIQUE INDEX ballot_envelopes_ranked_one_per_voter
            ON ballot_envelopes (race_id, user_id) WHERE kind = 'ranked'
        ");
        DB::statement("
            CREATE UNIQUE INDEX ballot_envelopes_referendum_one_per_voter
            ON ballot_envelopes (referendum_question_id, user_id) WHERE kind = 'referendum'
        ");

        DB::statement('ALTER TABLE ballots ALTER COLUMN race_id DROP NOT NULL');
        DB::statement('ALTER TABLE ballots ADD COLUMN referendum_question_id uuid');
        DB::statement('
            ALTER TABLE ballots ADD CONSTRAINT ballots_referendum_question_id_foreign
            FOREIGN KEY (referendum_question_id) REFERENCES referendum_questions(id) ON DELETE RESTRICT
        ');
        DB::statement("
            ALTER TABLE ballots ADD CONSTRAINT ballots_kind_pairing_check
            CHECK ((kind = 'ranked'     AND race_id IS NOT NULL AND referendum_question_id IS NULL)
                OR (kind = 'referendum' AND referendum_question_id IS NOT NULL AND race_id IS NULL))
        ");
        DB::statement('CREATE INDEX ballots_referendum_question_counted_idx ON ballots (referendum_question_id, counted)');

        // ── chamber_vote_proposals: this batch's proposal kinds ─────────────
        DB::statement('ALTER TABLE chamber_vote_proposals DROP CONSTRAINT IF EXISTS chamber_vote_proposals_kind_check');
        DB::statement(
            "ALTER TABLE chamber_vote_proposals ADD CONSTRAINT chamber_vote_proposals_kind_check " .
            "CHECK (proposal_kind IN ('committee_creation', 'election_board_creation', " .
            "'admin_office_creation', 'rules_of_order', 'ethics_code', " .
            "'referendum_delegation', 'referendum_act_modification', " .
            "'emergency_invocation', 'emergency_renewal'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE chamber_vote_proposals DROP CONSTRAINT IF EXISTS chamber_vote_proposals_kind_check');
        DB::statement(
            "ALTER TABLE chamber_vote_proposals ADD CONSTRAINT chamber_vote_proposals_kind_check " .
            "CHECK (proposal_kind IN ('committee_creation', 'election_board_creation', " .
            "'admin_office_creation', 'rules_of_order', 'ethics_code'))"
        );

        DB::statement('DROP INDEX IF EXISTS ballots_referendum_question_counted_idx');
        DB::statement('ALTER TABLE ballots DROP CONSTRAINT IF EXISTS ballots_kind_pairing_check');
        DB::statement('ALTER TABLE ballots DROP CONSTRAINT IF EXISTS ballots_referendum_question_id_foreign');
        DB::statement('ALTER TABLE ballots DROP COLUMN IF EXISTS referendum_question_id');
        DB::statement('ALTER TABLE ballots ALTER COLUMN race_id SET NOT NULL');

        DB::statement('DROP INDEX IF EXISTS ballot_envelopes_referendum_one_per_voter');
        DB::statement('DROP INDEX IF EXISTS ballot_envelopes_ranked_one_per_voter');
        DB::statement('ALTER TABLE ballot_envelopes ADD CONSTRAINT ballot_envelopes_race_id_user_id_kind_unique UNIQUE (race_id, user_id, kind)');
        DB::statement('ALTER TABLE ballot_envelopes DROP CONSTRAINT IF EXISTS ballot_envelopes_kind_pairing_check');
        DB::statement('ALTER TABLE ballot_envelopes ALTER COLUMN race_id SET NOT NULL');
        DB::statement('ALTER TABLE ballot_envelopes DROP CONSTRAINT IF EXISTS ballot_envelopes_referendum_question_id_foreign');

        Schema::dropIfExists('referendum_questions');
        Schema::dropIfExists('petition_signatures');
        Schema::dropIfExists('petitions');
    }
};
