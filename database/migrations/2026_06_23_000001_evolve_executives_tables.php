<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-1 (PHASE_D_DESIGN_executive §A) — evolve the Phase 0 executives stubs
 * into the ESM-16 machine. ADDITIVE on a live dev DB: every existing row
 * is `forming`, so the new status CHECK back-fills as a no-op.
 *
 *  - executives: status CHECK (the stub had only a type CHECK); the
 *    delegation/conversion provenance columns. ONE row per jurisdiction
 *    is preserved — conversion EVOLVES the same row (committee→individual
 *    flips `type`); the delegated era's member rows close, never delete.
 *    `modified` is deliberately NOT a status: modification is an event
 *    (exec_office_alter process + audit), not a resting state.
 *  - executive_members: provenance (`selection`), lifecycle (`status`),
 *    the ex-officio link (`legislature_member_id` — a delegated member's
 *    term IS their legislative seat's term; `term_id` stays NULL for them
 *    so no second lockstep source of truth exists), and the elected-era
 *    links (`elected_in_race_id`, `term_id`).
 *    The one-principal rule for type='individual' is an ENGINE rule, not
 *    a partial unique — `type` lives on the parent row.
 *  - elections.executive_id — the office an `executive`-kind election
 *    fills. (`board_id` lands with the boards migration, D-2.)
 *  - election_races: `exec_committee` joins seat_kind, and the seats
 *    CHECK is recut — Art. III §2 floors the committee model at 5 with
 *    NO ceiling; the blanket 1–9 band stays for chamber kinds only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── executives ──────────────────────────────────────────────────────
        Schema::table('executives', function (Blueprint $table) {
            $table->uuid('delegation_law_id')->nullable();
            $table->foreign('delegation_law_id')->references('id')->on('laws')->nullOnDelete();

            $table->text('delegated_scope')->nullable();

            $table->uuid('conversion_process_id')->nullable();
            $table->foreign('conversion_process_id')->references('id')->on('multi_jurisdiction_votes')->nullOnDelete();

            $table->uuid('conversion_law_id')->nullable();
            $table->foreign('conversion_law_id')->references('id')->on('laws')->nullOnDelete();

            $table->timestampTz('converted_at')->nullable();

            $table->smallInteger('delegated_member_count')->nullable();
        });

        DB::statement(
            "ALTER TABLE executives ADD CONSTRAINT executives_status_check CHECK (status IN (" .
            "'forming', 'delegated', 'conversion_voted', 'elected', 'dissolved', 'reverted'))"
        );
        DB::statement(
            'ALTER TABLE executives ADD CONSTRAINT executives_delegated_member_count_check ' .
            'CHECK (delegated_member_count IS NULL OR delegated_member_count >= 5)'
        );

        // ── executive_members ───────────────────────────────────────────────
        Schema::table('executive_members', function (Blueprint $table) {
            $table->uuid('legislature_member_id')->nullable();
            $table->foreign('legislature_member_id')->references('id')->on('legislature_members')->nullOnDelete();

            $table->uuid('elected_in_race_id')->nullable();
            $table->foreign('elected_in_race_id')->references('id')->on('election_races')->nullOnDelete();

            $table->uuid('term_id')->nullable();
            $table->foreign('term_id')->references('id')->on('terms')->nullOnDelete();

            $table->string('selection', 24)->default('delegated_proportional');
            $table->string('status', 16)->default('seated');

            $table->index(['executive_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE executive_members ADD CONSTRAINT executive_members_selection_check CHECK (selection IN (" .
            "'delegated_proportional', 'elected_stv', 'elected_rcv', 'advisor_derivation', 'succession'))"
        );
        DB::statement(
            "ALTER TABLE executive_members ADD CONSTRAINT executive_members_status_check CHECK (status IN (" .
            "'seated', 'left', 'removed', 'succeeded', 'term_ended'))"
        );

        // ── elections.executive_id ──────────────────────────────────────────
        Schema::table('elections', function (Blueprint $table) {
            $table->uuid('executive_id')->nullable();
            $table->foreign('executive_id')->references('id')->on('executives')->nullOnDelete();

            $table->index(['executive_id', 'status']);
        });

        // ── election_races seat_kind/seats recut ────────────────────────────
        DB::statement('ALTER TABLE election_races DROP CONSTRAINT IF EXISTS election_races_seat_kind_check');
        DB::statement(
            "ALTER TABLE election_races ADD CONSTRAINT election_races_seat_kind_check " .
            "CHECK (seat_kind IN ('type_a', 'type_b', 'single', 'exec_committee'))"
        );

        DB::statement('ALTER TABLE election_races DROP CONSTRAINT IF EXISTS election_races_seats_check');
        DB::statement(
            'ALTER TABLE election_races ADD CONSTRAINT election_races_seats_check CHECK ( ' .
            "(seat_kind IN ('type_a', 'type_b') AND seats BETWEEN 1 AND 9) " .
            "OR (seat_kind = 'single' AND seats = 1) " .
            "OR (seat_kind = 'exec_committee' AND seats >= 5) )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE election_races DROP CONSTRAINT IF EXISTS election_races_seats_check');
        DB::statement(
            'ALTER TABLE election_races ADD CONSTRAINT election_races_seats_check ' .
            'CHECK (seats BETWEEN 1 AND 9)'
        );
        DB::statement('ALTER TABLE election_races DROP CONSTRAINT IF EXISTS election_races_seat_kind_check');
        DB::statement(
            "ALTER TABLE election_races ADD CONSTRAINT election_races_seat_kind_check " .
            "CHECK (seat_kind IN ('type_a', 'type_b', 'single'))"
        );

        Schema::table('elections', function (Blueprint $table) {
            $table->dropForeign(['executive_id']);
            $table->dropIndex(['executive_id', 'status']);
            $table->dropColumn('executive_id');
        });

        DB::statement('ALTER TABLE executive_members DROP CONSTRAINT IF EXISTS executive_members_status_check');
        DB::statement('ALTER TABLE executive_members DROP CONSTRAINT IF EXISTS executive_members_selection_check');
        Schema::table('executive_members', function (Blueprint $table) {
            $table->dropForeign(['legislature_member_id']);
            $table->dropForeign(['elected_in_race_id']);
            $table->dropForeign(['term_id']);
            $table->dropIndex(['executive_id', 'status']);
            $table->dropColumn(['legislature_member_id', 'elected_in_race_id', 'term_id', 'selection', 'status']);
        });

        DB::statement('ALTER TABLE executives DROP CONSTRAINT IF EXISTS executives_delegated_member_count_check');
        DB::statement('ALTER TABLE executives DROP CONSTRAINT IF EXISTS executives_status_check');
        Schema::table('executives', function (Blueprint $table) {
            $table->dropForeign(['delegation_law_id']);
            $table->dropForeign(['conversion_process_id']);
            $table->dropForeign(['conversion_law_id']);
            $table->dropColumn([
                'delegation_law_id', 'delegated_scope', 'conversion_process_id',
                'conversion_law_id', 'converted_at', 'delegated_member_count',
            ]);
        });
    }
};
