<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CASES E-5 (PHASE_E_DESIGN_cases_juries §A) — `verdicts`,
 * `sentencing_orders`, `warrants`.
 *
 *  - verdicts: the decided outcome (panel ruling and/or jury verdict);
 *    `double_jeopardy_flag` is set true ⇔ the case is criminal (the
 *    implication is held by CaseService in one transaction with
 *    cases.double_jeopardy_locked — a cross-table CHECK is impractical in PG).
 *  - sentencing_orders (F-JDG-009): only on a guilty criminal verdict
 *    (CaseService asserts verdict.outcome='guilty' before insert).
 *  - warrants (F-JDG-010, Art. II §8 Arrest Warrant Requirement): a NOT-NULL
 *    stated_reason and (for arrest) a max hold duration are the two
 *    constitutional facts — a warrant with neither is structurally unfilable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdicts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('case_id');
            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();

            $table->string('decided_by', 12);
            $table->string('outcome', 20);

            $table->smallInteger('panel_vote_for')->nullable();
            $table->smallInteger('panel_vote_against')->nullable();
            $table->boolean('jury_unanimous')->nullable();

            $table->text('summary')->nullable();

            // Set true ⇔ cases.kind='criminal' (held by CaseService).
            $table->boolean('double_jeopardy_flag')->default(false);

            $table->uuid('record_id')->nullable();

            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE verdicts ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE verdicts ADD CONSTRAINT verdicts_decided_by_check '.
            "CHECK (decided_by IN ('panel', 'jury'))"
        );
        DB::statement(
            'ALTER TABLE verdicts ADD CONSTRAINT verdicts_outcome_check CHECK (outcome IN ('.
            "'guilty', 'not_guilty', 'liable', 'not_liable', 'dismissed', 'for_petitioner', 'for_respondent'))"
        );
        // One operative verdict per case; appeals create a NEW case.
        DB::statement(
            'CREATE UNIQUE INDEX verdicts_case_unique ON verdicts (case_id) WHERE deleted_at IS NULL'
        );

        Schema::create('sentencing_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('case_id');
            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();

            $table->uuid('verdict_id');
            $table->foreign('verdict_id')->references('id')->on('verdicts')->restrictOnDelete();

            $table->uuid('issued_by_seat_id');
            $table->foreign('issued_by_seat_id')->references('id')->on('judicial_seats')->restrictOnDelete();

            $table->text('terms');

            $table->timestampTz('effective_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->string('status', 12);

            $table->uuid('record_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE sentencing_orders ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE sentencing_orders ADD CONSTRAINT sentencing_orders_status_check '.
            "CHECK (status IN ('issued', 'stayed', 'vacated', 'completed'))"
        );

        Schema::create('warrants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('case_id');
            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();

            $table->uuid('issued_by_seat_id');
            $table->foreign('issued_by_seat_id')->references('id')->on('judicial_seats')->restrictOnDelete();

            $table->string('kind', 12);

            // Art. II §8 — "establishing the reason for the arrest" (NOT NULL).
            $table->text('stated_reason');

            // Art. II §8 — "the maximum duration an Individual can be held"
            // (> 0; required for arrest, NULL for search/seizure — service).
            $table->integer('max_hold_duration_hours')->nullable();

            $table->uuid('subject_user_id')->nullable();
            $table->foreign('subject_user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('status', 12);

            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->uuid('record_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE warrants ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE warrants ADD CONSTRAINT warrants_kind_check '.
            "CHECK (kind IN ('arrest', 'search', 'seizure'))"
        );
        // The constitutional facts at the DB belt (Art. II §8).
        DB::statement(
            "ALTER TABLE warrants ADD CONSTRAINT warrants_stated_reason_present CHECK (btrim(stated_reason) <> '')"
        );
        DB::statement(
            'ALTER TABLE warrants ADD CONSTRAINT warrants_max_hold_positive '.
            'CHECK (max_hold_duration_hours IS NULL OR max_hold_duration_hours > 0)'
        );
        DB::statement(
            'ALTER TABLE warrants ADD CONSTRAINT warrants_status_check '.
            "CHECK (status IN ('issued', 'executed', 'expired', 'quashed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('warrants');
        Schema::dropIfExists('sentencing_orders');
        Schema::dropIfExists('verdicts');
    }
};
