<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-4 (PHASE_D_DESIGN_executive §A) — the executive-actions tables.
 *
 *  - executive_orders (F-EXE-005): pre-issuance scope validation. A
 *    REJECTED order PERSISTS (`rejected_pre_issuance` + verbatim
 *    citation) and publishes to public_records BEFORE the 422 rethrows —
 *    the Phase D exit-criterion mechanism. `target_domain` keeps the
 *    three civic-process values in the enum so the ATTEMPT is typed
 *    honestly; they are auto-reject values (Art. II §7).
 *    `judicial_review_case_id` carries NO FK — Phase E adds it when
 *    `cases` exists (the state machine cannot be retrofitted cheaply,
 *    so under_review/struck land now).
 *  - policy_proposals (F-EXE-002): the board adopts/amends/declines —
 *    proposals never bypass the board.
 *  - executive_investigations (F-EXE-004): records_access is DECLARATIVE
 *    jsonb in Phase D (no record-ACL substrate until E/F — flagged
 *    deferral); findings publication is the operative duty.
 *  - governor_removal_requests (F-EXE-003): deliberately NOT a
 *    removal_proceedings row — governor removal is ordinary-majority
 *    hiring-and-firing (owner ruling #14); folding it into the
 *    supermajority machinery would invite threshold drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── executive_orders ────────────────────────────────────────────────
        Schema::create('executive_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('executive_id');
            $table->foreign('executive_id')->references('id')->on('executives')->restrictOnDelete();

            $table->uuid('issued_by_member_id');
            $table->foreign('issued_by_member_id')->references('id')->on('executive_members')->restrictOnDelete();

            $table->uuid('department_id')->nullable();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();

            // EO-YYYY-NN per executive per year; NULL until issued.
            $table->string('order_no', 20)->nullable();

            $table->string('title');
            $table->text('body');

            // The cited enabling instrument (charter ⇒ the department's
            // charter law id; uniform law-shaped scope checks).
            $table->string('enabling_type', 20);
            $table->uuid('enabling_id');

            $table->string('target_domain', 24);

            $table->string('status', 24)->default('drafted');

            $table->string('rejection_citation')->nullable();
            $table->text('rejection_reason')->nullable();

            // public_records linkage — issued AND rejected orders publish.
            $table->uuid('record_id')->nullable();

            // Phase E hook — NO FK until `cases` exists.
            $table->uuid('judicial_review_case_id')->nullable();

            $table->timestampTz('issued_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['executive_id', 'status']);
            $table->index('department_id');
        });

        DB::statement('ALTER TABLE executive_orders ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE executive_orders ADD CONSTRAINT executive_orders_enabling_type_check " .
            "CHECK (enabling_type IN ('law', 'emergency_power', 'charter'))"
        );
        DB::statement(
            "ALTER TABLE executive_orders ADD CONSTRAINT executive_orders_target_domain_check " .
            "CHECK (target_domain IN ('department_operations', 'public_works', 'emergency_response', " .
            "'administration', 'other', 'electoral_process', 'judicial_process', 'legislative_process'))"
        );
        DB::statement(
            "ALTER TABLE executive_orders ADD CONSTRAINT executive_orders_status_check " .
            "CHECK (status IN ('drafted', 'scope_validated', 'issued', 'rejected_pre_issuance', " .
            "'under_review', 'struck', 'revoked'))"
        );
        // A rejected order ALWAYS carries its citation; nothing else does.
        DB::statement(
            "ALTER TABLE executive_orders ADD CONSTRAINT executive_orders_rejection_citation_check " .
            "CHECK ((status = 'rejected_pre_issuance') = (rejection_citation IS NOT NULL))"
        );

        // ── policy_proposals ────────────────────────────────────────────────
        Schema::create('policy_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('executive_id');
            $table->foreign('executive_id')->references('id')->on('executives')->restrictOnDelete();

            $table->uuid('department_id');
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();

            $table->uuid('proposed_by_member_id');
            $table->foreign('proposed_by_member_id')->references('id')->on('executive_members')->restrictOnDelete();

            $table->string('title');
            $table->text('text');

            $table->uuid('board_vote_id')->nullable();
            $table->foreign('board_vote_id')->references('id')->on('chamber_votes')->nullOnDelete();

            $table->string('decision', 12)->default('pending');
            $table->text('amended_text')->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['department_id', 'decision']);
        });

        DB::statement('ALTER TABLE policy_proposals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE policy_proposals ADD CONSTRAINT policy_proposals_decision_check " .
            "CHECK (decision IN ('pending', 'adopted', 'amended', 'declined'))"
        );

        // ── executive_investigations ────────────────────────────────────────
        Schema::create('executive_investigations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('executive_id');
            $table->foreign('executive_id')->references('id')->on('executives')->restrictOnDelete();

            $table->uuid('department_id')->nullable();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();

            $table->uuid('ordered_by_member_id');
            $table->foreign('ordered_by_member_id')->references('id')->on('executive_members')->restrictOnDelete();

            $table->text('scope');

            // Declarative in Phase D (no record-ACL substrate yet).
            $table->jsonb('records_access')->default('[]');

            $table->uuid('findings_record_id')->nullable();

            $table->string('outcome', 24)->default('open');

            // The F-EXE-002 / F-EXE-003 / I-ADM row the branch produced.
            $table->string('outcome_ref_type', 32)->nullable();
            $table->uuid('outcome_ref_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['executive_id', 'outcome']);
        });

        DB::statement('ALTER TABLE executive_investigations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE executive_investigations ADD CONSTRAINT executive_investigations_outcome_check " .
            "CHECK (outcome IN ('open', 'policy_proposal', 'removal_request', 'legislative_referral', " .
            "'closed_no_finding'))"
        );

        // ── governor_removal_requests ───────────────────────────────────────
        Schema::create('governor_removal_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('board_seat_id');
            $table->foreign('board_seat_id')->references('id')->on('board_seats')->restrictOnDelete();

            $table->uuid('requested_by_member_id');
            $table->foreign('requested_by_member_id')->references('id')->on('executive_members')->restrictOnDelete();

            // Good-faith finding — published at filing.
            $table->text('grounds');
            $table->uuid('record_id')->nullable();

            $table->uuid('vote_id')->nullable();
            $table->foreign('vote_id')->references('id')->on('chamber_votes')->nullOnDelete();

            $table->string('outcome', 12)->default('pending');
            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['board_seat_id', 'outcome']);
        });

        DB::statement('ALTER TABLE governor_removal_requests ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE governor_removal_requests ADD CONSTRAINT governor_removal_requests_outcome_check " .
            "CHECK (outcome IN ('pending', 'removed', 'retained'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('governor_removal_requests');
        Schema::dropIfExists('executive_investigations');
        Schema::dropIfExists('policy_proposals');
        Schema::dropIfExists('executive_orders');
    }
};
