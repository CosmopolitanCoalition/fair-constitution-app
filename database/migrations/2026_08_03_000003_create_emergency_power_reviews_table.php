<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CHALLENGE E-3 (PHASE_E_DESIGN_challenge_law §A) — `emergency_power_reviews`
 * (F-JDG-007, Art. II §7 "Emergency Powers are subject to Judicial review").
 *
 * The emergency power already carries the hook columns
 * (judicial_review_case_id, review_outcome, statuses under_review|struck|
 * narrowed). This table records the review ACT and its disposition (the power
 * table holds only the current state).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_power_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('emergency_power_id');
            $table->foreign('emergency_power_id')->references('id')->on('emergency_powers')->restrictOnDelete();

            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->restrictOnDelete();

            $table->uuid('case_id')->nullable();
            $table->foreign('case_id')->references('id')->on('cases')->nullOnDelete();

            // A review may be triggered BY an F-IND-016 challenge of the power,
            // or sua sponte (NULL).
            $table->uuid('challenge_id')->nullable();
            $table->foreign('challenge_id')->references('id')->on('constitutional_challenges')->nullOnDelete();

            // The Art. II §7 limit allegedly breached.
            $table->string('review_basis', 28);

            // Maps to EmergencyPower::STATUS_* (active/narrowed/struck).
            $table->string('outcome', 12);

            // The narrowed scope when outcome='narrowed' (Art. II §7 area/methods limits).
            $table->uuid('narrowed_area_jurisdiction_id')->nullable();
            $table->foreign('narrowed_area_jurisdiction_id')->references('id')->on('jurisdictions')->nullOnDelete();
            $table->jsonb('narrowed_methods')->nullable();

            $table->text('opinion_text');
            $table->uuid('record_id')->nullable();
            $table->timestampTz('issued_at');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['emergency_power_id', 'outcome']);
        });

        DB::statement('ALTER TABLE emergency_power_reviews ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // The Art. II §7 limit clauses (duration ≤ max, area ≤ authority,
        // methods ≤ constitutional order, non-disruption of civic processes,
        // closed cause enum).
        DB::statement(
            'ALTER TABLE emergency_power_reviews ADD CONSTRAINT emergency_power_reviews_basis_check '.
            "CHECK (review_basis IN ('duration', 'area', 'methods', 'civic_process_disruption', 'cause'))"
        );
        DB::statement(
            'ALTER TABLE emergency_power_reviews ADD CONSTRAINT emergency_power_reviews_outcome_check '.
            "CHECK (outcome IN ('upheld', 'narrowed', 'struck'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_power_reviews');
    }
};
