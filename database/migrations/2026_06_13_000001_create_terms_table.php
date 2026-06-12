<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-1 — `terms`: the CLK-10 lockstep substrate (PHASE_B_DESIGN_schema_lifecycle §A B-1).
 *
 * One row per held term of office. Two term classes:
 *
 *   lockstep          — elected terms anchored to the legislature's election
 *                       cycle. HARDENED: no service exposes an update path
 *                       for `ends_on` on lockstep terms (CLK-10 is the
 *                       no-API guarantee, pinned by TermLockstepTest).
 *                       Countback / special-election replacements INHERIT
 *                       the original `ends_on` — never a fresh term.
 *   civil_appointment — 10-year civil/judicial appointments (Art. II §9,
 *                       Art. IV §1), written by the appointments pipeline.
 *
 * `office_kind` carries the full enum now; Phase B only writes
 * `legislature_seat` / `election_board_member`. `office_type`/`office_id`
 * is the polymorphic seat row (`legislature_members`, later
 * `executive_members`…), filled after seating.
 *
 * `jurisdiction_id` has NO FK — dev mass-reseeds hard-delete jurisdictions
 * (same rationale as clock_timers.jurisdiction_id).
 *
 * `source_election_id` / `source_appointment_id` are forward refs: their
 * FKs are added in B-3 (evolve_elections) and B-2 (appointments) per the
 * design's dependency order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('office_kind', 24);

            // Polymorphic seat row — filled after seating.
            $table->string('office_type', 64)->nullable();
            $table->uuid('office_id')->nullable();

            $table->uuid('holder_user_id');
            $table->foreign('holder_user_id')->references('id')->on('users')->restrictOnDelete();

            // No FK by design — see docblock.
            $table->uuid('jurisdiction_id');

            // Lockstep anchor for elected terms.
            $table->uuid('legislature_id')->nullable();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->nullOnDelete();

            $table->string('term_class', 20);

            // HARDENED: no update path for ends_on on lockstep terms
            // (engine rule — see class docblock).
            $table->date('starts_on');
            $table->date('ends_on');

            // FKs added in B-3 / B-2 respectively (forward refs).
            $table->uuid('source_election_id')->nullable();
            $table->uuid('source_appointment_id')->nullable();

            $table->string('status', 12)->default('active');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['legislature_id', 'status']);
            $table->index(['holder_user_id', 'status']);
            $table->index(['office_type', 'office_id']);
        });

        DB::statement('ALTER TABLE terms ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            "ALTER TABLE terms ADD CONSTRAINT terms_office_kind_check CHECK (office_kind IN (" .
            "'legislature_seat', 'executive_seat', 'judicial_seat', 'election_board_member', " .
            "'board_governor', 'admin_staff', 'civil_officer'))"
        );
        DB::statement(
            "ALTER TABLE terms ADD CONSTRAINT terms_term_class_check " .
            "CHECK (term_class IN ('lockstep', 'civil_appointment'))"
        );
        DB::statement(
            "ALTER TABLE terms ADD CONSTRAINT terms_status_check " .
            "CHECK (status IN ('active', 'completed', 'vacated', 'removed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
