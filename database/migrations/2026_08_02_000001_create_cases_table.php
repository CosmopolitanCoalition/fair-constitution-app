<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CASES E-1 (PHASE_E_DESIGN_cases_juries §A) — `cases`, the spine of
 * WF-JUD-03 (ESM-CASE). One dispute before a judiciary; the case ESM is
 * pinned by CaseLifecycleTest. `panel_id`/`jury_id` are forward refs (FKs
 * added in E-3/E-4); `double_jeopardy_locked` is the persisted Art. II §8
 * fact the hardened re-prosecution bar reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // case-YYYY-NNN per judiciary per year (pg_advisory_xact_lock,
            // the EnactmentService::allocateActNumber pattern).
            $table->string('docket_no', 24);

            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->restrictOnDelete();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->string('kind', 16);
            $table->string('title');
            $table->text('statement_of_claim')->nullable();

            $table->string('claimed_severity', 12)->nullable(); // the filer's input
            $table->string('court_severity', 20)->nullable();    // the court's classification (drives panel size)

            $table->boolean('jury_entitled')->default(false);
            $table->boolean('jury_waived')->default(false);

            $table->string('filed_via_form', 16);
            $table->uuid('filed_by_user_id')->nullable();
            $table->foreign('filed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->uuid('filed_on_behalf_of_user_id')->nullable();
            $table->foreign('filed_on_behalf_of_user_id')->references('id')->on('users')->nullOnDelete();

            // FK added in E-8 (advocates) — forward ref like panel/jury below.
            $table->uuid('advocate_id')->nullable();

            // Forward refs (panels E-3, juries E-4): plain uuid now, FK later.
            $table->uuid('panel_id')->nullable();
            $table->uuid('jury_id')->nullable();

            // The appellate self-reference (the `appealed` ESM state retrofit;
            // appellate mechanics are a thin follow-up — design §F deferral 3).
            // FK attached after creation (self-ref needs the PK to exist first).
            $table->uuid('appeal_of_case_id')->nullable();

            $table->string('status', 20);

            // Art. II §8 — set true the moment a criminal case reaches a
            // terminal verdict; the hardened re-prosecution bar reads this.
            $table->boolean('double_jeopardy_locked')->default(false);

            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['judiciary_id', 'status']);
            $table->index('jurisdiction_id');
            $table->index(['kind', 'status']);
        });

        DB::statement('ALTER TABLE cases ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE cases ADD CONSTRAINT cases_kind_check '.
            "CHECK (kind IN ('civil', 'criminal', 'administrative', 'constitutional'))"
        );
        DB::statement(
            'ALTER TABLE cases ADD CONSTRAINT cases_claimed_severity_check '.
            "CHECK (claimed_severity IS NULL OR claimed_severity IN ('minor', 'moderate', 'serious'))"
        );
        DB::statement(
            'ALTER TABLE cases ADD CONSTRAINT cases_court_severity_check '.
            "CHECK (court_severity IS NULL OR court_severity IN ('minor', 'moderate', 'serious', 'constitutional_major'))"
        );
        DB::statement(
            'ALTER TABLE cases ADD CONSTRAINT cases_filed_via_form_check '.
            "CHECK (filed_via_form IN ('F-IND-017', 'F-ADV-001', 'F-IND-016'))"
        );
        // ESM-CASE — the case lifecycle status enum.
        DB::statement(
            'ALTER TABLE cases ADD CONSTRAINT cases_status_check CHECK (status IN ('.
            "'filed', 'accepted', 'paneled', 'jury_empaneled', 'heard', 'deliberation', ".
            "'decided', 'sentenced', 'closed', 'dismissed', 'appealed'))"
        );

        // docket numbers are per-court (uniqueness honours soft deletes).
        DB::statement(
            'CREATE UNIQUE INDEX cases_judiciary_docket_unique ON cases (judiciary_id, docket_no) '.
            'WHERE deleted_at IS NULL'
        );

        // The appellate self-reference — attached now the cases PK exists.
        Schema::table('cases', function (Blueprint $table) {
            $table->foreign('appeal_of_case_id')->references('id')->on('cases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};
