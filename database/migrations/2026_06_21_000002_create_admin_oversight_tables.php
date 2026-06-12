<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C (chamber ops §D) — `admin_offices` (I-ADM) +
 * `misconduct_investigations` + `removal_proceedings` (F-LEG-022 canonical;
 * F-SPK-007 presides).
 *
 * Cross-scope columns (created_by_vote_id / created_by_law_id / vote_id /
 * findings_record_id) are plain uuid soft refs — chamber_votes / laws /
 * public_records belong to the sibling votes-laws migration set; same
 * no-FK precedent as appointments.consent_vote_id (Phase B).
 *
 * removal_proceedings ESM (minimal, §D.3):
 *   opened → presiding_designated → voted → closed(outcome)
 * with the PROTECTED-validator rule `removal.presider`
 * (presided_by_member_id ≠ the subject member — Art. II §3) enforced at
 * the engine layer; the DB cannot express it because subject_type is
 * polymorphic.
 *
 * `kind` reserves judge_removal / executive_removal (removal parity,
 * Art. II/III/IV) — Phase C activates only legislature_members subjects
 * (no seated judges or elected executives exist yet; deferral per design).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── admin_offices (I-ADM) ───────────────────────────────────────────
        Schema::create('admin_offices', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            // Cross-scope soft refs (no FK — see file docblock).
            $table->uuid('created_by_vote_id')->nullable();
            $table->uuid('created_by_law_id')->nullable();

            $table->string('status', 12)->default('created');

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE admin_offices ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE admin_offices ADD CONSTRAINT admin_offices_status_check " .
            "CHECK (status IN ('created', 'staffed', 'dissolved'))"
        );
        // One live office per legislature.
        DB::statement(
            "CREATE UNIQUE INDEX admin_offices_one_live ON admin_offices (legislature_id) " .
            "WHERE status != 'dissolved' AND deleted_at IS NULL"
        );

        // ── misconduct_investigations ───────────────────────────────────────
        Schema::create('misconduct_investigations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('admin_office_id');
            $table->foreign('admin_office_id')->references('id')->on('admin_offices')->cascadeOnDelete();

            // INV-YYYY-NN, unique per office.
            $table->string('code', 16);

            // legislature_members | users | legislatures (app-layer enum).
            $table->string('subject_type', 40);
            $table->uuid('subject_id');

            // Any resident; NULL = own motion / system (CLK-02 referral).
            $table->uuid('complainant_user_id')->nullable();
            $table->foreign('complainant_user_id')->references('id')->on('users')->nullOnDelete();

            $table->text('summary');

            $table->string('status', 24)->default('intake');

            // public_records.id — findings publication (soft ref).
            $table->uuid('findings_record_id')->nullable();

            // FK added below (removal_proceedings is created after).
            $table->uuid('referred_proceeding_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['admin_office_id', 'code']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement('ALTER TABLE misconduct_investigations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE misconduct_investigations ADD CONSTRAINT misconduct_investigations_status_check " .
            "CHECK (status IN ('intake', 'investigating', 'referred', 'closed_no_finding'))"
        );

        // ── removal_proceedings (F-LEG-022 / F-SPK-007) ─────────────────────
        Schema::create('removal_proceedings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            $table->string('kind', 20);

            $table->string('subject_type', 40);
            $table->uuid('subject_id');

            $table->uuid('source_investigation_id')->nullable();
            $table->foreign('source_investigation_id')->references('id')->on('misconduct_investigations')->nullOnDelete();

            // NULL until designated (opened → presiding_designated).
            $table->uuid('presided_by_member_id')->nullable();
            $table->foreign('presided_by_member_id')->references('id')->on('legislature_members')->restrictOnDelete();

            $table->string('opened_via', 16);

            // chamber_votes.id (supermajority class) — soft ref.
            $table->uuid('vote_id')->nullable();

            $table->string('status', 24)->default('opened');
            $table->string('outcome', 12)->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('legislature_id');
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement('ALTER TABLE removal_proceedings ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE removal_proceedings ADD CONSTRAINT removal_proceedings_kind_check " .
            "CHECK (kind IN ('impeachment', 'censure', 'expulsion', 'judge_removal', 'executive_removal'))"
        );
        DB::statement(
            "ALTER TABLE removal_proceedings ADD CONSTRAINT removal_proceedings_status_check " .
            "CHECK (status IN ('opened', 'presiding_designated', 'voted', 'closed'))"
        );
        DB::statement(
            "ALTER TABLE removal_proceedings ADD CONSTRAINT removal_proceedings_outcome_check " .
            "CHECK (outcome IS NULL OR outcome IN ('removed', 'censured', 'expelled', 'retained'))"
        );

        // Close the investigations → proceedings circle.
        DB::statement(
            'ALTER TABLE misconduct_investigations ADD CONSTRAINT misconduct_investigations_referred_proceeding_id_foreign ' .
            'FOREIGN KEY (referred_proceeding_id) REFERENCES removal_proceedings(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE misconduct_investigations DROP CONSTRAINT IF EXISTS ' .
            'misconduct_investigations_referred_proceeding_id_foreign'
        );

        Schema::dropIfExists('removal_proceedings');
        Schema::dropIfExists('misconduct_investigations');
        Schema::dropIfExists('admin_offices');
    }
};
