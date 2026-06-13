<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-2 (PHASE_E_DESIGN_judiciary §A) — evolve the judicial_seats stub to
 * carry the seat CLASS and provenance, mirroring board_seats /
 * executive_members. ADDITIVE: every stub row is `vacant` with the new
 * default class, so the recut CHECKs back-fill as a no-op.
 *
 *  - seat_class: constituent_nominated | committee_nominated | elected
 *    (the prompt's three seat classes; the appointed-creation default).
 *  - nominating_jurisdiction_id: WHICH constituent nominated this judge
 *    (Art. IV §2 "equal number by each constituent"); NO cascade — a
 *    dissolved constituent never cascade-deletes a seated judge.
 *  - appointment_id / elected_in_race_id / term_id: the consent-pipeline,
 *    elected-era, and term-row links (exactly like board_seats).
 *  - status: the recut bench-seat lifecycle (the stub's `recused` is
 *    DROPPED — recusal is a per-case concern owned by the cases agent,
 *    never a seat-pool resting state).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judicial_seats', function (Blueprint $table) {
            $table->string('seat_class', 24)->default('committee_nominated');

            $table->uuid('nominating_jurisdiction_id')->nullable();
            $table->foreign('nominating_jurisdiction_id')->references('id')->on('jurisdictions')->nullOnDelete();

            $table->uuid('appointment_id')->nullable();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();

            $table->uuid('elected_in_race_id')->nullable();
            $table->foreign('elected_in_race_id')->references('id')->on('election_races')->nullOnDelete();

            $table->uuid('term_id')->nullable();
            $table->foreign('term_id')->references('id')->on('terms')->nullOnDelete();

            $table->index(['judiciary_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE judicial_seats ADD CONSTRAINT judicial_seats_seat_class_check CHECK (seat_class IN ('.
            "'constituent_nominated', 'committee_nominated', 'elected'))"
        );

        // Recut the stub's vacant|seated|recused|retired to the bench-seat
        // lifecycle. `recused` is dropped (per-case, cases-agent scope).
        DB::statement('ALTER TABLE judicial_seats DROP CONSTRAINT IF EXISTS judicial_seats_status_check');
        DB::statement(
            'ALTER TABLE judicial_seats ADD CONSTRAINT judicial_seats_status_check CHECK (status IN ('.
            "'vacant', 'nominated', 'seated', 'removal_requested', 'removed', 'term_ended', 'retired'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE judicial_seats DROP CONSTRAINT IF EXISTS judicial_seats_status_check');
        DB::statement(
            'ALTER TABLE judicial_seats ADD CONSTRAINT judicial_seats_status_check '.
            "CHECK (status IN ('vacant', 'seated', 'recused', 'retired'))"
        );

        DB::statement('ALTER TABLE judicial_seats DROP CONSTRAINT IF EXISTS judicial_seats_seat_class_check');

        Schema::table('judicial_seats', function (Blueprint $table) {
            $table->dropForeign(['nominating_jurisdiction_id']);
            $table->dropForeign(['appointment_id']);
            $table->dropForeign(['elected_in_race_id']);
            $table->dropForeign(['term_id']);
            $table->dropIndex(['judiciary_id', 'status']);
            $table->dropColumn([
                'seat_class', 'nominating_jurisdiction_id', 'appointment_id',
                'elected_in_race_id', 'term_id',
            ]);
        });
    }
};
