<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-2 — `appointments` + `election_boards` + `election_board_members`
 * (PHASE_B_DESIGN_schema_lifecycle §A B-2).
 *
 * `appointments` is the generic civil-appointment pipeline (R-08 in Phase B;
 * R-18/29/30 later). `consent_vote_id` carries NO FK — chamber_votes is
 * Phase C; nullable covers bootstrap.
 *
 * `election_boards` (I-ELB): `is_bootstrap = true` marks the system-as-board
 * row constituted by ActivationService step 3.5 ("temporary · replacement
 * queued" until WF-ELE-10 retires it in Phase C). `created_by_act_id`
 * carries NO FK — laws is Phase C. At most one active board per
 * jurisdiction (partial unique).
 *
 * `election_board_members.user_id` NULL = THE SYSTEM ITSELF on a bootstrap
 * board — exactly one synthetic "member" row so every F-ELB filing has a
 * board-member provenance without inventing a fake user. The CHECK
 * `(user_id IS NOT NULL) OR (status = 'seated')` pins that the system row
 * is always seated.
 *
 * Also adds the forward-ref FK terms.source_appointment_id → appointments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── appointments ────────────────────────────────────────────────────
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('appointable_type', 64);
            $table->uuid('appointable_id');

            $table->uuid('nominee_user_id');
            $table->foreign('nominee_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->uuid('nominated_by')->nullable();
            $table->string('nominated_via_form', 16)->nullable();

            // No FK — chamber_votes is Phase C; nullable covers bootstrap.
            $table->uuid('consent_vote_id')->nullable();

            $table->string('status', 12)->default('nominated');

            $table->uuid('term_id')->nullable();
            $table->foreign('term_id')->references('id')->on('terms')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['appointable_type', 'appointable_id']);
        });

        DB::statement('ALTER TABLE appointments ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_status_check " .
            "CHECK (status IN ('nominated', 'consented', 'rejected', 'seated', 'ended'))"
        );

        // Forward ref declared in B-1.
        DB::statement(
            'ALTER TABLE terms ADD CONSTRAINT terms_source_appointment_id_foreign ' .
            'FOREIGN KEY (source_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL'
        );

        // ── election_boards (I-ELB) ─────────────────────────────────────────
        Schema::create('election_boards', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            // Creating legislature; NULL for bootstrap.
            $table->uuid('legislature_id')->nullable();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->nullOnDelete();

            // No FK — laws is Phase C.
            $table->uuid('created_by_act_id')->nullable();

            $table->boolean('is_bootstrap')->default(false);

            $table->string('status', 12)->default('forming');
            $table->timestampTz('retired_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('legislature_id');
        });

        DB::statement('ALTER TABLE election_boards ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE election_boards ADD CONSTRAINT election_boards_status_check " .
            "CHECK (status IN ('forming', 'active', 'retired'))"
        );
        DB::statement(
            "CREATE UNIQUE INDEX election_boards_one_active ON election_boards (jurisdiction_id) " .
            "WHERE status = 'active' AND deleted_at IS NULL"
        );

        // ── election_board_members ──────────────────────────────────────────
        Schema::create('election_board_members', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('election_board_id');
            $table->foreign('election_board_id')->references('id')->on('election_boards')->cascadeOnDelete();

            // NULL = the system itself on a bootstrap board (see docblock).
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->uuid('appointment_id')->nullable();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();

            $table->string('status', 12)->default('nominated');

            $table->date('term_starts_on')->nullable();
            $table->date('term_ends_on')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('election_board_id');
        });

        DB::statement('ALTER TABLE election_board_members ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE election_board_members ADD CONSTRAINT election_board_members_status_check " .
            "CHECK (status IN ('nominated', 'seated', 'removed', 'term_ended'))"
        );
        // The system row (user_id NULL) is always seated.
        DB::statement(
            "ALTER TABLE election_board_members ADD CONSTRAINT election_board_members_system_seated_check " .
            "CHECK ((user_id IS NOT NULL) OR (status = 'seated'))"
        );
        DB::statement(
            "CREATE UNIQUE INDEX election_board_members_one_seat ON election_board_members " .
            "(election_board_id, user_id) WHERE status = 'seated' AND user_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('election_board_members');
        Schema::dropIfExists('election_boards');

        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_source_appointment_id_foreign');
        Schema::dropIfExists('appointments');
    }
};
