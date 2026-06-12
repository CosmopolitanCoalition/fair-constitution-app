<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-5 (PHASE_C_DESIGN_votes_laws §A) — the dual-supermajority substrate
 * for Phase D/E/F processes (executive office creation/alteration,
 * judiciary conversion, cultural institutions, additional articles,
 * unions, disintermediation).
 *
 * Schema lands NOW (the expensive-to-retrofit part); Phase C wiring is
 * MultiJurisdictionVoteService::{open, recordConsent, evaluate} only.
 * No form consumes it until F-LEG-015 (Phase D) — deferral justified in
 * the design.
 *
 * `required` is snapshotted through the PROTECTED functions:
 * supermajority basis ⇒ ConstitutionalValidator::supermajority(total);
 * unanimity ⇒ total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multi_jurisdiction_votes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('kind', 24);

            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();

            $table->uuid('initiating_legislature_id');
            $table->foreign('initiating_legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            // The initiator's own supermajority where the kind requires one.
            $table->uuid('initiating_vote_id')->nullable();
            $table->foreign('initiating_vote_id')->references('id')->on('chamber_votes')->nullOnDelete();

            $table->string('basis', 16);

            $table->smallInteger('constituent_total');
            $table->smallInteger('required');

            $table->smallInteger('yes_count')->default(0);
            $table->smallInteger('no_count')->default(0);

            $table->string('status', 8)->default('open');

            $table->timestampTz('opens_at')->nullable();
            $table->timestampTz('closes_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['kind', 'status']);
        });

        DB::statement('ALTER TABLE multi_jurisdiction_votes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE multi_jurisdiction_votes ADD CONSTRAINT multi_jurisdiction_votes_kind_check
            CHECK (kind IN ('exec_office_create','exec_office_alter','judiciary_convert','cultural_institution',
                            'additional_articles','union','disintermediation'))
        ");
        DB::statement("ALTER TABLE multi_jurisdiction_votes ADD CONSTRAINT multi_jurisdiction_votes_basis_check CHECK (basis IN ('supermajority','unanimity'))");
        DB::statement("ALTER TABLE multi_jurisdiction_votes ADD CONSTRAINT multi_jurisdiction_votes_status_check CHECK (status IN ('open','passed','failed','expired'))");

        Schema::create('constituent_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('process_id');
            $table->foreign('process_id')->references('id')->on('multi_jurisdiction_votes')->cascadeOnDelete();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->uuid('legislature_id')->nullable();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->nullOnDelete();

            // That constituent chamber's own peg-quorum vote.
            $table->uuid('chamber_vote_id')->nullable();
            $table->foreign('chamber_vote_id')->references('id')->on('chamber_votes')->nullOnDelete();

            $table->string('result', 8)->default('pending');
            $table->timestampTz('decided_at')->nullable();

            $table->unique(['process_id', 'jurisdiction_id']);
        });

        DB::statement('ALTER TABLE constituent_consents ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE constituent_consents ADD CONSTRAINT constituent_consents_result_check CHECK (result IN ('pending','yes','no'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('constituent_consents');
        Schema::dropIfExists('multi_jurisdiction_votes');
    }
};
