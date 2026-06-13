<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CASES E-8 (PHASE_E_DESIGN_cases_juries §A) — `advocates` (R-21 ·
 * F-IND-015): "keeps the bar of advocates zealous and competent". The
 * registration is at the judiciary level (inherited by descendant courts).
 *
 * Rights posture (Art. I + Art. IV §4): registration is available to any
 * R-03 (associated resident); the handler rejects only on association +
 * duplicate, never a merits/identity test. Competence qualifications are a
 * property of the BAR a jurisdiction maintains, not a gate on the client's
 * underlying right to representation.
 *
 * The forward `cases.advocate_id`, `case_parties.represented_by_advocate_id`,
 * and `case_filings.advocate_id` refs get their FKs here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advocates', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->restrictOnDelete();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->string('status', 12)->default('registered');
            $table->text('qualifications_note')->nullable();

            $table->timestampTz('registered_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['judiciary_id', 'status']);
        });

        DB::statement('ALTER TABLE advocates ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE advocates ADD CONSTRAINT advocates_status_check '.
            "CHECK (status IN ('registered', 'suspended', 'withdrawn'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX advocates_user_judiciary_unique ON advocates (user_id, judiciary_id) '.
            'WHERE deleted_at IS NULL'
        );

        // Now the forward advocate refs get their FKs.
        Schema::table('cases', function (Blueprint $table) {
            $table->foreign('advocate_id')->references('id')->on('advocates')->nullOnDelete();
        });
        Schema::table('case_parties', function (Blueprint $table) {
            $table->foreign('represented_by_advocate_id')->references('id')->on('advocates')->nullOnDelete();
        });
        DB::statement(
            'ALTER TABLE case_filings ADD CONSTRAINT case_filings_advocate_id_foreign '.
            'FOREIGN KEY (advocate_id) REFERENCES advocates(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE case_filings DROP CONSTRAINT IF EXISTS case_filings_advocate_id_foreign');
        Schema::table('case_parties', function (Blueprint $table) {
            $table->dropForeign(['represented_by_advocate_id']);
        });
        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['advocate_id']);
        });

        Schema::dropIfExists('advocates');
    }
};
