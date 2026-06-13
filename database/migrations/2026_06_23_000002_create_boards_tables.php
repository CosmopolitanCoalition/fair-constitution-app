<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-2 (PHASE_D_DESIGN_executive §A) — THE UNIFIED boards + board_seats
 * set. One table set for department boards of governors, CGC boards, and
 * private-org boards (MASTER_PLAN binding decision): one co-determination
 * engine, one validity rule, one seat substrate.
 *
 *  - boards.worker_seats is the co-determination engine's OUTPUT — only
 *    the orgs designer's CoDeterminationService writes it (documented
 *    contract; the column ships here because the table does).
 *  - boards.worker_headcount is the denormalized count of active
 *    F-IND-014 worker rows (the orgs designer's polymorphic-employer
 *    table feeds it — Art. III §6 applies identically to departments).
 *  - boards.composition_valid=false BLOCKS board acts except the curing
 *    elections (engine rule in ChamberVoteService::open).
 *  - boards.cycle_months: org-board term cycle ([COORD-EXEC] request from
 *    the orgs design §C.4 — default mirrors election_interval_months).
 *  - board_seats.seat_class: departments use governor + worker_elected;
 *    private orgs owner_elected + worker_elected; CGCs governor +
 *    worker_elected (owner ruling #12 — the BoG stands where
 *    shareholders would).
 *
 * Also ships the cross-designer linkage this table unlocks (the orgs
 * design's D-O8 items 1–2, "first lander ships it"):
 *  - elections.board_id — the body an org_board_* election fills;
 *  - vote_casts widened so BOARD SEATS can cast on body_type='board'
 *    chamber votes: member_id nullable + board_seat_id, XOR CHECK, the
 *    one-cast unique recut as two partial uniques. The lane table needs
 *    zero change (Phase C designed it so).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── boards ──────────────────────────────────────────────────────────
        Schema::create('boards', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 'departments' | 'organizations' (CGC = an organizations row).
            $table->string('boardable_type', 32);
            $table->uuid('boardable_id');

            $table->smallInteger('owner_seats');
            $table->smallInteger('worker_seats')->default(0);
            $table->integer('worker_headcount')->default(0);

            // FK added post board_seats creation.
            $table->uuid('chair_seat_id')->nullable();

            $table->boolean('composition_valid')->default(true);

            // Org-board term cycle ([COORD-EXEC], orgs design §C.4).
            $table->smallInteger('cycle_months')->default(60);

            $table->string('status', 12)->default('forming');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['boardable_type', 'boardable_id']);
        });

        DB::statement('ALTER TABLE boards ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE boards ADD CONSTRAINT boards_boardable_type_check " .
            "CHECK (boardable_type IN ('departments', 'organizations'))"
        );
        DB::statement('ALTER TABLE boards ADD CONSTRAINT boards_owner_seats_check CHECK (owner_seats >= 1)');
        DB::statement('ALTER TABLE boards ADD CONSTRAINT boards_worker_seats_check CHECK (worker_seats >= 0)');
        DB::statement(
            "ALTER TABLE boards ADD CONSTRAINT boards_status_check " .
            "CHECK (status IN ('forming', 'active', 'dissolved'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX boards_one_per_body ON boards (boardable_type, boardable_id) ' .
            'WHERE deleted_at IS NULL'
        );

        // ── board_seats ─────────────────────────────────────────────────────
        Schema::create('board_seats', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('board_id');
            $table->foreign('board_id')->references('id')->on('boards')->cascadeOnDelete();

            $table->string('seat_class', 16);
            $table->smallInteger('seat_no');

            $table->uuid('holder_user_id')->nullable();
            $table->foreign('holder_user_id')->references('id')->on('users')->nullOnDelete();

            // Governor pipeline (F-EXE-001 → F-LEG-020 consent).
            $table->uuid('appointment_id')->nullable();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();

            // Owner/worker STV tracks (orgs designer's elections).
            $table->uuid('elected_in_race_id')->nullable();
            $table->foreign('elected_in_race_id')->references('id')->on('election_races')->nullOnDelete();

            $table->uuid('term_id')->nullable();
            $table->foreign('term_id')->references('id')->on('terms')->nullOnDelete();

            $table->boolean('is_chair')->default(false);

            $table->string('status', 20)->default('vacant');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['board_id', 'status']);
            $table->index('holder_user_id');
        });

        DB::statement('ALTER TABLE board_seats ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE board_seats ADD CONSTRAINT board_seats_seat_class_check " .
            "CHECK (seat_class IN ('governor', 'owner_elected', 'worker_elected'))"
        );
        DB::statement(
            "ALTER TABLE board_seats ADD CONSTRAINT board_seats_status_check CHECK (status IN (" .
            "'vacant', 'nominated', 'seated', 'removal_requested', 'removed', 'term_ended'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX board_seats_one_seat_no ON board_seats (board_id, seat_no) ' .
            'WHERE deleted_at IS NULL'
        );
        DB::statement(
            "CREATE UNIQUE INDEX board_seats_one_chair ON board_seats (board_id) " .
            "WHERE is_chair AND status = 'seated' AND deleted_at IS NULL"
        );

        Schema::table('boards', function (Blueprint $table) {
            $table->foreign('chair_seat_id')->references('id')->on('board_seats')->nullOnDelete();
        });

        // ── elections.board_id (orgs D-O8 item 1 — first lander ships) ─────
        Schema::table('elections', function (Blueprint $table) {
            $table->uuid('board_id')->nullable();
            $table->foreign('board_id')->references('id')->on('boards')->restrictOnDelete();

            $table->index(['board_id', 'status']);
        });

        // ── vote_casts widening (orgs D-O8 item 2 — first lander ships) ────
        Schema::table('vote_casts', function (Blueprint $table) {
            $table->uuid('board_seat_id')->nullable();
            $table->foreign('board_seat_id')->references('id')->on('board_seats')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE vote_casts ALTER COLUMN member_id DROP NOT NULL');
        DB::statement(
            'ALTER TABLE vote_casts ADD CONSTRAINT vote_casts_caster_xor ' .
            'CHECK ((member_id IS NOT NULL) <> (board_seat_id IS NOT NULL))'
        );
        DB::statement('ALTER TABLE vote_casts DROP CONSTRAINT IF EXISTS vote_casts_vote_id_member_id_unique');
        DB::statement(
            'CREATE UNIQUE INDEX vote_casts_one_member_cast ON vote_casts (vote_id, member_id) ' .
            'WHERE member_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX vote_casts_one_board_seat_cast ON vote_casts (vote_id, board_seat_id) ' .
            'WHERE board_seat_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vote_casts_one_board_seat_cast');
        DB::statement('DROP INDEX IF EXISTS vote_casts_one_member_cast');
        DB::statement('ALTER TABLE vote_casts DROP CONSTRAINT IF EXISTS vote_casts_caster_xor');
        Schema::table('vote_casts', function (Blueprint $table) {
            $table->dropForeign(['board_seat_id']);
            $table->dropColumn('board_seat_id');
        });
        DB::statement('DELETE FROM vote_casts WHERE member_id IS NULL');
        DB::statement('ALTER TABLE vote_casts ALTER COLUMN member_id SET NOT NULL');
        DB::statement(
            'ALTER TABLE vote_casts ADD CONSTRAINT vote_casts_vote_id_member_id_unique ' .
            'UNIQUE (vote_id, member_id)'
        );

        Schema::table('elections', function (Blueprint $table) {
            $table->dropForeign(['board_id']);
            $table->dropIndex(['board_id', 'status']);
            $table->dropColumn('board_id');
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->dropForeign(['chair_seat_id']);
        });

        Schema::dropIfExists('board_seats');
        Schema::dropIfExists('boards');
    }
};
