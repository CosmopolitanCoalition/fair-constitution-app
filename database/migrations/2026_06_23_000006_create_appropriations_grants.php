<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-6 (PHASE_D_DESIGN_executive §A) — appropriations + grants, minimal
 * viable per the executive-actions contract: the register + application
 * + audit-chained disbursement. Full budgeting (appropriations-bill UX)
 * is post-F backlog (justified deferral).
 *
 * Service invariants (GrantService, FOR UPDATE on the appropriation):
 * award ≤ remaining; Σ disbursements ≤ awarded; every award/disbursement
 * audit-chained (WF-SYS-04) + published.
 *
 * grant_disbursements is APPEND-ONLY: no updates, no soft deletes — a
 * disbursement happened or it did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── appropriations ──────────────────────────────────────────────────
        Schema::create('appropriations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Legislatures appropriate by act.
            $table->uuid('law_id');
            $table->foreign('law_id')->references('id')->on('laws')->restrictOnDelete();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            // Administering executive.
            $table->uuid('executive_id');
            $table->foreign('executive_id')->references('id')->on('executives')->restrictOnDelete();

            $table->string('line');
            $table->decimal('amount', 18, 2);
            $table->decimal('remaining', 18, 2);

            $table->string('status', 12)->default('active');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['executive_id', 'status']);
        });

        DB::statement('ALTER TABLE appropriations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE appropriations ADD CONSTRAINT appropriations_status_check " .
            "CHECK (status IN ('active', 'exhausted', 'lapsed'))"
        );
        DB::statement(
            'ALTER TABLE appropriations ADD CONSTRAINT appropriations_remaining_check ' .
            'CHECK (remaining >= 0 AND remaining <= amount)'
        );

        // ── grant_applications ──────────────────────────────────────────────
        Schema::create('grant_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('appropriation_id');
            $table->foreign('appropriation_id')->references('id')->on('appropriations')->restrictOnDelete();

            $table->uuid('applicant_org_id');
            $table->foreign('applicant_org_id')->references('id')->on('organizations')->restrictOnDelete();

            $table->decimal('amount', 18, 2);
            $table->text('purpose');

            $table->string('status', 12)->default('submitted');

            $table->uuid('decided_by_member_id')->nullable();
            $table->foreign('decided_by_member_id')->references('id')->on('executive_members')->nullOnDelete();

            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['appropriation_id', 'status']);
        });

        DB::statement('ALTER TABLE grant_applications ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE grant_applications ADD CONSTRAINT grant_applications_amount_check CHECK (amount > 0)');
        DB::statement(
            "ALTER TABLE grant_applications ADD CONSTRAINT grant_applications_status_check " .
            "CHECK (status IN ('submitted', 'awarded', 'declined', 'withdrawn'))"
        );

        // ── grant_disbursements (append-only) ───────────────────────────────
        Schema::create('grant_disbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('grant_applications')->restrictOnDelete();

            $table->decimal('amount', 18, 2);

            $table->uuid('disbursed_by_member_id');
            $table->foreign('disbursed_by_member_id')->references('id')->on('executive_members')->restrictOnDelete();

            $table->timestampTz('disbursed_at');
            $table->timestampTz('created_at')->useCurrent();

            // No updated_at, no soft deletes — append-only.

            $table->index('application_id');
        });

        DB::statement('ALTER TABLE grant_disbursements ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE grant_disbursements ADD CONSTRAINT grant_disbursements_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('grant_disbursements');
        Schema::dropIfExists('grant_applications');
        Schema::dropIfExists('appropriations');
    }
};
