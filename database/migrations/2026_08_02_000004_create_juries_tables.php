<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CASES E-4 (PHASE_E_DESIGN_cases_juries §A) — `juries` + `jury_members`:
 * "a jury of their peers" (Art. IV §4). One jury per criminal case (when
 * entitled + not waived). The draw seed is PUBLISHED to the audit chain —
 * "anyone can verify the draw". voir dire removes CONFLICTS ONLY (never
 * opinion / demographics / politics — the `excusal_reason` CHECK is the belt).
 *
 * No fee field exists anywhere on the jury path: jury service may never carry
 * a fee (Art. II §8 Prohibition of Compulsory Payments) — the no-fee shield
 * is STRUCTURAL.
 *
 * The forward `cases.jury_id` ref (E-1) gets its FK here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('juries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('case_id');
            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();

            // The F-JDG-002 filing row (case_filings) — forward ref, no FK.
            $table->uuid('selection_order_id')->nullable();

            $table->integer('pool_size');

            $table->uuid('eligible_jurisdiction_id');
            $table->foreign('eligible_jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->smallInteger('seats')->default(12);
            $table->smallInteger('alternates')->default(2);

            // Published to the audit chain — anyone can verify the draw.
            $table->string('draw_seed', 64);

            $table->timestampTz('report_on')->nullable();

            $table->string('status', 16);

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE juries ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE juries ADD CONSTRAINT juries_status_check '.
            "CHECK (status IN ('drawing', 'voir_dire', 'empaneled', 'deliberating', 'discharged'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX juries_case_unique ON juries (case_id) WHERE deleted_at IS NULL'
        );

        Schema::table('cases', function (Blueprint $table) {
            $table->foreign('jury_id')->references('id')->on('juries')->nullOnDelete();
        });

        Schema::create('jury_members', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jury_id');
            $table->foreign('jury_id')->references('id')->on('juries')->cascadeOnDelete();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            $table->string('seat_kind', 12);
            $table->smallInteger('seat_no')->nullable();

            $table->string('screening_status', 16);

            // Conflict / hardship ONLY — never opinion/demographics/politics.
            $table->string('excusal_reason', 24)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'screening_status']);
        });

        DB::statement('ALTER TABLE jury_members ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE jury_members ADD CONSTRAINT jury_members_seat_kind_check '.
            "CHECK (seat_kind IN ('juror', 'alternate'))"
        );
        DB::statement(
            'ALTER TABLE jury_members ADD CONSTRAINT jury_members_screening_status_check CHECK (screening_status IN ('.
            "'summoned', 'screening', 'cleared', 'excused', 'empaneled', 'discharged'))"
        );
        // voir dire removes conflicts only (and confirmed hardship excuses) —
        // never opinion/demographics/politics (the hardened gloss as a belt).
        DB::statement(
            'ALTER TABLE jury_members ADD CONSTRAINT jury_members_excusal_reason_check '.
            "CHECK (excusal_reason IS NULL OR excusal_reason IN ('conflict', 'hardship'))"
        );

        // A resident is drawn once per jury.
        DB::statement(
            'CREATE UNIQUE INDEX jury_members_jury_user_unique ON jury_members (jury_id, user_id) '.
            'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['jury_id']);
        });

        Schema::dropIfExists('jury_members');
        Schema::dropIfExists('juries');
    }
};
