<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-9 (PHASE_C_DESIGN_votes_laws §A) — emergency_powers (ESM-12) +
 * emergency_power_renewals.
 *
 * The row is created ON VOTE ADOPTION, not at proposal — the proposal
 * lives in chamber_vote_proposals (kind 'emergency_invocation'); a failed
 * invoke leaves a failed vote + audit trail, no power row. `invoked` is a
 * transient state of the VOTE, never of the row.
 *
 * Art. II §7 hard rails:
 *  - cause CHECK in (natural_disaster, actual_invasion) — closed enum,
 *    anything else rejected PRE-VOTE by the engine;
 *  - declared_duration_days CHECK 1..90 (DB belt; the engine additionally
 *    clamps to the resolved emergency_powers_max_days);
 *  - renewals extend from CURRENT expiry, each renewal ≤ max — total
 *    lifetime may exceed 90 only through repeated fresh supermajorities.
 *
 * `area_geom` (custom sub-area MULTIPOLYGON) is DEFERRED to the manual
 * line-drawing pass — named areas are jurisdiction-composites in Phase C
 * (same justification as district composites, q-ledger #q4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_powers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            // Declaring authority.
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->string('cause', 20);

            // e.g. "Hurricane Dorinda landfall".
            $table->string('label');

            $table->smallInteger('declared_duration_days');

            // CHECK-less; the engine validates = self or descendant of
            // jurisdiction_id (≤ the legislature's authority).
            $table->uuid('area_jurisdiction_id');
            $table->foreign('area_jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            // "Within constitutional order" free text; published.
            $table->text('methods');

            // The supermajority adoption.
            $table->uuid('invoke_vote_id');
            $table->foreign('invoke_vote_id')->references('id')->on('chamber_votes')->restrictOnDelete();

            $table->string('status', 16)->default('active');

            // CLK-03 anchor.
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');

            // Phase E hook (F-JDG-007) — no FK, the institution is forming.
            $table->uuid('judicial_review_case_id')->nullable();
            $table->string('review_outcome', 12)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['legislature_id', 'status']);
            $table->index(['area_jurisdiction_id', 'status']);
        });

        DB::statement('ALTER TABLE emergency_powers ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE emergency_powers ADD CONSTRAINT emergency_powers_cause_check
            CHECK (cause IN ('natural_disaster','actual_invasion'))
        ");
        DB::statement('
            ALTER TABLE emergency_powers ADD CONSTRAINT emergency_powers_duration_check
            CHECK (declared_duration_days BETWEEN 1 AND 90)
        ');
        DB::statement("
            ALTER TABLE emergency_powers ADD CONSTRAINT emergency_powers_status_check
            CHECK (status IN ('active','under_review','renewed','expired','struck','narrowed'))
        ");
        DB::statement("
            ALTER TABLE emergency_powers ADD CONSTRAINT emergency_powers_review_outcome_check
            CHECK (review_outcome IS NULL OR review_outcome IN ('upheld','narrowed','struck'))
        ");

        Schema::create('emergency_power_renewals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('emergency_power_id');
            $table->foreign('emergency_power_id')->references('id')->on('emergency_powers')->cascadeOnDelete();

            // The fresh supermajority.
            $table->uuid('vote_id');
            $table->foreign('vote_id')->references('id')->on('chamber_votes')->restrictOnDelete();

            $table->smallInteger('extension_days');

            $table->timestampTz('previous_expires_at');
            $table->timestampTz('new_expires_at');

            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE emergency_power_renewals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('
            ALTER TABLE emergency_power_renewals ADD CONSTRAINT emergency_power_renewals_extension_check
            CHECK (extension_days BETWEEN 1 AND 90)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_power_renewals');
        Schema::dropIfExists('emergency_powers');
    }
};
