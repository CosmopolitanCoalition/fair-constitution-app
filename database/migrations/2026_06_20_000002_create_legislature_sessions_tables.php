<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-2 (PHASE_C_DESIGN_votes_laws §A) — `legislature_sessions` +
 * `session_attendance`. Sessions drive the ESM-08 motion context and the
 * CLK-02 meeting deadline; attendance feeds the quorum CALL and the public
 * record ONLY — it is NEVER a vote denominator (every chamber-vote
 * threshold snapshots serving members, not attendance).
 *
 * `serving_at_open` = ALL currently serving members (a vacant seat is
 * simply not serving — Montegiardino: 9 seats, 8 serving, quorum 5).
 * `quorum_required` = ConstitutionalValidator::quorum(serving_at_open).
 * The two *_by_kind jsonb columns carry the bicameral per-kind peg
 * snapshots (q-ledger #q7 — each kind must meet its OWN quorum).
 *
 * `session_attendance` carries no soft deletes: corrections re-record
 * (the unique upserts), history lives in the audit chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legislature_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            $table->integer('session_no');

            // F-SPK-001 caller; NULL = system (first session / CLK-02 posture).
            $table->uuid('called_by_member_id')->nullable();
            $table->foreign('called_by_member_id')->references('id')->on('legislature_members')->nullOnDelete();

            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('adjourned_at')->nullable();

            $table->smallInteger('serving_at_open')->nullable();
            $table->smallInteger('quorum_required')->nullable();
            $table->jsonb('serving_by_kind')->nullable();
            $table->jsonb('quorum_required_by_kind')->nullable();

            // F-SPK-003 outcome.
            $table->boolean('quorum_met')->nullable();

            // Ordered items {slot, kind, ref_type, ref_id, title, locked, status}.
            // Kinds: emergency_power (slot-1 locked), constitutional_matter
            // (slot-2 locked, Phase E feed), committee_report, bill_floor,
            // motion, statement, general.
            $table->jsonb('agenda')->default('[]');

            // F-SPK-009 — by uuid, no FK (public_records is append-only).
            $table->uuid('minutes_record_id')->nullable();

            $table->string('status', 16)->default('scheduled');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['legislature_id', 'session_no']);
            $table->index(['legislature_id', 'status']);
        });

        DB::statement('ALTER TABLE legislature_sessions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE legislature_sessions ADD CONSTRAINT legislature_sessions_status_check
            CHECK (status IN ('scheduled','open','adjourned','failed_quorum','cancelled'))
        ");

        Schema::create('session_attendance', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('legislature_sessions')->cascadeOnDelete();

            $table->uuid('member_id');
            $table->foreign('member_id')->references('id')->on('legislature_members')->restrictOnDelete();

            $table->string('status', 12)->default('absent');

            // 'F-LEG-002' | 'F-SPK-008' | 'system' (open-time materialization).
            $table->string('recorded_via_form', 12)->nullable();
            $table->timestampTz('recorded_at')->useCurrent();

            $table->unique(['session_id', 'member_id']);
        });

        DB::statement('ALTER TABLE session_attendance ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE session_attendance ADD CONSTRAINT session_attendance_status_check
            CHECK (status IN ('present','absent','compelled','excused'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('session_attendance');
        Schema::dropIfExists('legislature_sessions');
    }
};
