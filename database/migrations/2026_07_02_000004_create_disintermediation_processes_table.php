<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (F-LEG-030, Art. V §8) — Disintermediation. An intermediary
 * jurisdiction dissolves when ALL its constituents agree (UNANIMITY, not
 * supermajority) AND its encompassing jurisdiction consents. Its Acts are
 * incorporated into its former constituents (the law-merge engine), and its
 * children re-point to the encompassing jurisdiction.
 *
 * `law_merge_resolutions` records the per-law decision (incorporate via
 * EnactmentService::amendLaw / defer / lapse) so the merge is auditable and the
 * version history of every incorporated law is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disintermediation_processes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('intermediary_jurisdiction_id');
            $table->foreign('intermediary_jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->uuid('encompassing_jurisdiction_id');
            $table->foreign('encompassing_jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            // UNANIMITY MultiJurisdictionVote over the intermediary's constituents.
            $table->uuid('constituent_process_id')->nullable();
            $table->boolean('encompassing_consent')->default(false);
            $table->uuid('encompassing_consent_vote_id')->nullable();

            $table->string('status', 16)->default('open'); // open | passed | failed | merged | expired

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['intermediary_jurisdiction_id', 'status']);
        });

        DB::statement('ALTER TABLE disintermediation_processes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE disintermediation_processes ADD CONSTRAINT disintermediation_processes_status_check CHECK (status IN ('open','passed','failed','merged','expired'))");

        Schema::create('law_merge_resolutions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('process_id');
            $table->foreign('process_id')->references('id')->on('disintermediation_processes')->cascadeOnDelete();

            // The intermediary law being merged + the constituent it merges into.
            $table->uuid('law_id');
            $table->uuid('target_jurisdiction_id')->nullable();

            $table->string('decision', 12); // incorporate | defer | lapse
            $table->uuid('resulting_law_id')->nullable(); // the constituent law amended/created
            $table->uuid('resolved_by')->nullable();

            $table->timestampsTz();

            $table->index('process_id');
        });

        DB::statement('ALTER TABLE law_merge_resolutions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE law_merge_resolutions ADD CONSTRAINT law_merge_resolutions_decision_check CHECK (decision IN ('incorporate','defer','lapse'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('law_merge_resolutions');
        Schema::dropIfExists('disintermediation_processes');
    }
};
