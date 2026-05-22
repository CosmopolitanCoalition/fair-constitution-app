<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Article III — Executives.
 *
 * Each jurisdiction with a legislature has one executive: either a
 * committee (5+ members elected by PR-STV, equal voting power, UK model)
 * or an individual (single winner via RCV, top-4 runners-up auto-seated as
 * advisors, US model). Both start as legislature-delegated and may convert
 * to directly elected by supermajority.
 *
 * This migration scaffolds the schema. Setup wizard's Step 4 inserts one
 * stub row per legislature in `status='forming'`. The elections engine
 * (Phase 2 of the master roadmap) populates members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executives', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            // committee (5+ co-equal members) | individual (single + 4 advisors)
            $table->string('type', 16)->default('committee');

            $table->unsignedSmallInteger('term_number')->default(1);
            $table->date('term_starts_on')->nullable();
            $table->date('term_ends_on')->nullable();

            // forming | active | dissolved
            $table->string('status', 16)->default('forming');

            // Origin tracking. parent_executive_id = direct delegating exec
            // (e.g. when a higher-level executive carves a sub-executive).
            // source_legislature_id = the legislature whose election seated
            // this executive, used during the legislature-delegated phase.
            $table->uuid('parent_executive_id')->nullable();
            $table->uuid('source_legislature_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('jurisdiction_id');
            $table->index('status');
            $table->unique(['jurisdiction_id', 'deleted_at'], 'executives_jurisdiction_unique');
        });

        DB::statement('ALTER TABLE executives ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        Schema::table('executives', function (Blueprint $table) {
            $table->foreign('parent_executive_id')->references('id')->on('executives')->nullOnDelete();
            $table->foreign('source_legislature_id')->references('id')->on('legislatures')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE executives ADD CONSTRAINT executives_type_check "
            . "CHECK (type IN ('committee', 'individual'))"
        );

        Schema::create('executive_members', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('executive_id');
            $table->foreign('executive_id')->references('id')->on('executives')->cascadeOnDelete();

            $table->uuid('user_id')->nullable();
            // FK to users will be added once user_id is widely used; this keeps
            // the model permissive for federation-imported members.

            // principal | advisor (advisors only valid for type='individual')
            $table->string('role', 16)->default('principal');

            // 0 = primary winner; 1..4 = runners-up auto-seated as advisors
            // (Article III §3 — top-4 in individual executive races become
            // automatic advisors).
            $table->unsignedTinyInteger('rank')->default(0);

            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('executive_id');
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE executive_members ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            "ALTER TABLE executive_members ADD CONSTRAINT executive_members_rank_check "
            . "CHECK (rank BETWEEN 0 AND 4)"
        );

        DB::statement(
            "ALTER TABLE executive_members ADD CONSTRAINT executive_members_role_check "
            . "CHECK (role IN ('principal', 'advisor'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_members');
        Schema::dropIfExists('executives');
    }
};
