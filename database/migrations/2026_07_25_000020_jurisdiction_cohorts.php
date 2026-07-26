<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `jurisdiction_cohorts` — one row per jurisdiction describing WHO lives there,
 * statistically, in the simulated world.
 *
 * WHY STORE IT AT ALL, when the cohort is a pure function of
 * `hash(jurisdiction_id) + version` and could be recomputed on demand?
 * Three reasons, all of them the operator's D3 ruling:
 *
 *  1. VISIBLE PROGRESS. Rows appearing is what the live bars count. A stage
 *     that computes in memory and commits at the end is exactly the black box
 *     he ruled against.
 *  2. RESUMABLE. A run that dies mid-planet resumes from the rows that exist,
 *     not from the beginning.
 *  3. AUDITABLE INPUT. The count's electorate is a stored, inspectable number
 *     with its seed beside it, so a published result can be re-derived by
 *     anyone — rather than being an argument about what the generator would
 *     have produced.
 *
 * It stays compact deliberately: ONE row per jurisdiction (~907k planet-wide,
 * a few hundred MB), holding the distribution's PARAMETERS, never expanded
 * people. The expansion into ballots happens in memory at count time, where
 * `BallotSet` collapses it again anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurisdiction_cohorts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('jurisdiction_id');
            $table->uuid('run_id')->nullable();

            // Part of the determinism key. Bump the version to regenerate the
            // world after a generator fix, instead of migrating 900k rows.
            $table->unsignedSmallInteger('version')->default(1);

            // The exact seed string handed to CohortBallotExpander. Stored so a
            // published count is reproducible by a third party.
            $table->string('seed', 128);

            // WorldPop's number for this jurisdiction — provenance, never the
            // civic population (see App\Support\CivicPopulation).
            $table->bigInteger('population')->default(0);

            // Ballots this jurisdiction's electorate casts = population × turnout,
            // floored at whatever the race legally needs.
            $table->bigInteger('electorate')->default(0);
            $table->unsignedSmallInteger('turnout_pct')->default(100);

            // Persona/preference parameters: language shares, occupation shares,
            // civic-desire priors, archetype weights.
            $table->jsonb('archetypes')->default('{}');

            $table->timestampsTz();

            $table->index(['run_id']);
        });

        DB::statement(
            'ALTER TABLE jurisdiction_cohorts ADD CONSTRAINT jurisdiction_cohorts_unit_uq '
            .'UNIQUE (jurisdiction_id, version)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisdiction_cohorts');
    }
};
