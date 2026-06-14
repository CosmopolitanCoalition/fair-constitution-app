<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (F-LEG-029, Art. V §7) — Union formation / joining / exit.
 *
 * DUAL ratification: a supermajority of the APPLICANT population (a civic
 * referendum) AND a supermajority of the union's CONSTITUENT jurisdictions
 * (a MultiJurisdictionVote, `union` kind). Both must pass. On passage a new
 * encompassing bicameral jurisdiction+legislature is created (formation), or the
 * applicant is admitted to / removed from the union (join / exit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('union_processes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('kind', 12); // formation | join | exit

            // Applicant jurisdiction(s) seeking union membership change.
            $table->jsonb('applicant_jurisdiction_ids')->default('[]');

            // The union jurisdiction (null for a formation until created).
            $table->uuid('union_jurisdiction_id')->nullable();

            // Compatibility diff over constitutional_settings + codified vars.
            $table->jsonb('compatibility_diff')->default('{}');
            $table->jsonb('codified_variables')->default('{}');

            // The two ratification meters.
            $table->uuid('applicant_referendum_election_id')->nullable();
            $table->boolean('applicant_supermajority_met')->default(false);
            $table->uuid('constituent_process_id')->nullable(); // → multi_jurisdiction_votes

            $table->string('status', 16)->default('open'); // open | passed | failed | expired
            $table->uuid('resulting_jurisdiction_id')->nullable();

            $table->uuid('initiating_legislature_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['kind', 'status']);
        });

        DB::statement('ALTER TABLE union_processes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE union_processes ADD CONSTRAINT union_processes_kind_check CHECK (kind IN ('formation','join','exit'))");
        DB::statement("ALTER TABLE union_processes ADD CONSTRAINT union_processes_status_check CHECK (status IN ('open','passed','failed','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('union_processes');
    }
};
