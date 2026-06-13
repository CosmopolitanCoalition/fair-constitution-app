<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CASES E-2 (PHASE_E_DESIGN_cases_juries §A) — `case_parties`: who is on
 * each side. `accused` marks the natural person entitled to a jury + counsel
 * (Art. IV §4). Principals are polymorphic (individual / organization /
 * jurisdiction / government_body); the right to representation is RENDERED,
 * never enforced as a precondition (a defendant may be self-represented).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('case_id');
            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();

            $table->string('party_role', 16);
            $table->string('party_type', 16);

            $table->uuid('party_user_id')->nullable();
            $table->foreign('party_user_id')->references('id')->on('users')->nullOnDelete();

            // Polymorphic non-individual principal — no FK (immutability over
            // cascade): organizations / jurisdictions / departments.
            $table->string('party_ref_type', 32)->nullable();
            $table->uuid('party_ref_id')->nullable();

            // Art. I right to representation; NULL = self-represented. FK in E-8.
            $table->uuid('represented_by_advocate_id')->nullable();

            $table->text('retainer_note')->nullable();

            $table->string('status', 12)->default('active');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['case_id', 'party_role']);
            $table->index('party_user_id');
        });

        DB::statement('ALTER TABLE case_parties ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE case_parties ADD CONSTRAINT case_parties_party_role_check '.
            "CHECK (party_role IN ('prosecution', 'plaintiff', 'defendant', 'respondent', 'intervenor', 'accused'))"
        );
        DB::statement(
            'ALTER TABLE case_parties ADD CONSTRAINT case_parties_party_type_check '.
            "CHECK (party_type IN ('individual', 'organization', 'jurisdiction', 'government_body'))"
        );
        DB::statement(
            'ALTER TABLE case_parties ADD CONSTRAINT case_parties_status_check '.
            "CHECK (status IN ('active', 'withdrawn', 'substituted'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('case_parties');
    }
};
