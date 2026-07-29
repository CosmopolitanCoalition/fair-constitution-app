<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `world_stats` — the nightly world rollup that feeds the Atlas.
 *
 * Authorized by ATLAS_DESIGN.md §3 (design approved by the operator 2026-07-29,
 * all §8 calls option (a)); built as lane 4's Wave 4 order ①. Migration slot
 * granted to lane 4 by the desk (W4 tick 8: "SLOT: world_stats OPEN for L4").
 *
 * WHY A ROLLUP TABLE EXISTS AT ALL — the shape carries the rule. Computing the
 * Atlas's vital signs per page load IS the ~75-second planet-scale aggregate,
 * and a live headcount would ALSO break k-anonymity: it would hand an observer
 * sub-minute resolution on numbers the reach snapshot deliberately publishes
 * once a day, letting them defeat the suppression by differencing. So the Atlas
 * reads one dated row, or it shows nothing. Same reason legitimacy is
 * snapshotted, and this table is deliberately its sibling.
 *
 * ONE ROW PER DATE, and `domains` is a JSONB envelope rather than a wide column
 * list. That is intentional: the nine domain cards will gain metrics as the
 * economy, achievements and mesh land, and a new metric must not cost a
 * migration slot. It also lets a domain be ABSENT — which is the whole privacy
 * posture: a figure we may not publish, or have not measured, is a GAP, and the
 * Atlas renders an absent key as an em-dash. A zeroed column could not express
 * that difference, and a zero would be a claim.
 *
 * NO `deleted_at`: like `legitimacy_snapshots` this is a dated record of what
 * the world looked like, not a mutable entity. Re-running a night replaces its
 * row (the unique on `as_of_date` makes the pass idempotent).
 *
 * Additive-only, REAL-dated after every prior migration. down() drops cleanly
 * for the cheap-moment proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_stats', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();

            // The night this rollup describes. One row per date — the writer
            // upserts on it, so a re-run is a replacement, never a duplicate.
            $table->date('as_of_date');

            // The nine domain cards, keyed by domain. An ABSENT key means "not
            // measured" and renders as a gap; it must never be written as 0.
            $table->jsonb('domains')->default('{}');

            $table->timestampsTz();

            $table->unique('as_of_date', 'world_stats_as_of_date_unique');
        });

        DB::statement('ALTER TABLE world_stats ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // The load-bearing rules, where a DBA reading psql will see them.
        DB::statement(<<<'SQL'
            COMMENT ON TABLE world_stats IS
                'Nightly world rollup feeding the Atlas (ATLAS_DESIGN.md). The Atlas reads the latest '
                'row and NEVER counts the world live: a live aggregate is both planet-scale slow and a '
                'k-anonymity leak (sub-minute resolution on a number published once a day). CI-1: a '
                'gauge, never a lever — nothing here is consulted on a rights path. CI-6: counts are '
                'scoped to jurisdictions this instance is authoritative for; peers publish their own row.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN world_stats.domains IS
                'JSONB envelope of per-domain totals. An ABSENT key means NOT MEASURED and renders as a '
                'gap in the UI — it must never be written as 0, and a suppressed reach snapshot must '
                'never contribute a number to a total (pinned: WorldRollupSuppressionTest).'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN world_stats.as_of_date IS
                'The night described. Unique: the nightly pass upserts, so re-running a date replaces it.'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('world_stats');
    }
};
