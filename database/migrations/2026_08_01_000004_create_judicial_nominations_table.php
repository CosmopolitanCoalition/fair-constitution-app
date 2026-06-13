<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-4 (PHASE_E_DESIGN_judiciary §A) — the nomination tracking row. The two
 * Art. IV §2 nomination paths both produce a set of nominees, each of which
 * gets its OWN F-LEG-021 consent vote. The appointments + consent vote
 * carry the seating; this row makes equal-per-constituent auditable (count
 * nominations grouped by nominating_jurisdiction_id must be uniform — the
 * constitutional invariant) and lets the surface render the constituent /
 * committee slate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('judicial_nominations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->cascadeOnDelete();

            // The seat this nominee fills (set when the seat is allocated).
            $table->uuid('seat_id')->nullable();
            $table->foreign('seat_id')->references('id')->on('judicial_seats')->nullOnDelete();

            // Copies judiciaries.nomination_mode.
            $table->string('mode', 20);

            // Constituent path: who nominated; NULL for committee.
            $table->uuid('nominating_jurisdiction_id')->nullable();
            $table->foreign('nominating_jurisdiction_id')->references('id')->on('jurisdictions')->nullOnDelete();

            $table->uuid('nominee_user_id');
            $table->foreign('nominee_user_id')->references('id')->on('users')->restrictOnDelete();

            // The consent-pipeline row.
            $table->uuid('appointment_id')->nullable();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();

            // public_records — nominee dossier published at nomination
            // (the F-EXE-001 posture). No FK (public_records is dev-reseeded).
            $table->uuid('dossier_record_id')->nullable();

            $table->string('status', 16);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['judiciary_id', 'status']);
            $table->index('nominating_jurisdiction_id');
        });

        DB::statement('ALTER TABLE judicial_nominations ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE judicial_nominations ADD CONSTRAINT judicial_nominations_mode_check '.
            "CHECK (mode IN ('constituent', 'committee'))"
        );
        DB::statement(
            'ALTER TABLE judicial_nominations ADD CONSTRAINT judicial_nominations_status_check '.
            "CHECK (status IN ('nominated', 'consented', 'rejected', 'withdrawn'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('judicial_nominations');
    }
};
