<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-10 — `vacancies`: ESM-13 Vacancy
 * (PHASE_B_DESIGN_schema_lifecycle §A B-10).
 *
 * Polymorphic seat (`seat_type`/`seat_id`); only `legislature_members` is
 * written in Phase B. Lifecycle: detected → declared → countback_running →
 * filled | countback_failed → special_election_scheduled. The countback is
 * a UNIVERSAL full re-run of the race's stored ballots with the vacating
 * candidacy struck (no filter of any kind); if ballots exhaust, the system
 * AUTO-schedules the special election inside
 * [declared_at + special_election_min_days, + max_days] (CLK-04 backstop —
 * discretion can never produce "no election").
 *
 * F-LEG-036 (vacancy declaration form) arrives in Phase C; Phase B writes
 * `declared_via_form = 'dev'` / system detection.
 *
 * `jurisdiction_id` has NO FK (dev reseeds — same rationale as terms).
 * Also adds the forward-ref FK elections.vacancy_id → vacancies (declared
 * in B-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Polymorphic seat; only legislature_members written in B.
            $table->string('seat_type', 64);
            $table->uuid('seat_id');

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->cascadeOnDelete();

            // No FK by design — see docblock.
            $table->uuid('jurisdiction_id');

            $table->uuid('declared_by')->nullable();
            $table->foreign('declared_by')->references('id')->on('users')->nullOnDelete();

            // F-LEG-036 arrives in C; B writes 'dev' / system detection.
            $table->string('declared_via_form', 16)->nullable();

            $table->string('status', 32)->default('detected');

            $table->timestampTz('detected_at');
            $table->timestampTz('declared_at')->nullable();

            $table->uuid('countback_tabulation_id')->nullable();
            $table->foreign('countback_tabulation_id')->references('id')->on('tabulations')->nullOnDelete();

            $table->uuid('special_election_id')->nullable();
            $table->foreign('special_election_id')->references('id')->on('elections')->nullOnDelete();

            $table->uuid('filled_by_user_id')->nullable();
            $table->timestampTz('filled_at')->nullable();

            $table->timestampsTz();

            $table->index(['seat_type', 'seat_id']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE vacancies ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE vacancies ADD CONSTRAINT vacancies_status_check CHECK (status IN (" .
            "'detected', 'declared', 'countback_running', 'filled', 'countback_failed', " .
            "'special_election_scheduled'))"
        );

        // Forward ref declared in B-3: special elections point at their vacancy.
        DB::statement(
            'ALTER TABLE elections ADD CONSTRAINT elections_vacancy_id_foreign ' .
            'FOREIGN KEY (vacancy_id) REFERENCES vacancies(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE elections DROP CONSTRAINT IF EXISTS elections_vacancy_id_foreign');
        Schema::dropIfExists('vacancies');
    }
};
