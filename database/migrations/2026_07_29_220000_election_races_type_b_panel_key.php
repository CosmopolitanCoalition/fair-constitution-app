<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TYPE B RACE FIX — THE SEATING HALF (Wave 4, lane 1; operator ruling
 * 2026-07-29, CLAUDE.md "Bicameral Support"). This is the SCHEMA half of
 * turning the WRONG pooled Type B race into the correct per-child / per-clump
 * shape. racePlan/createRaces are the code half (same commit family).
 *
 * The old, now-superseded doctrine (2026-07-26, migration
 * 2026_07_25_000012_type_b_at_large_unbound + the ElectionRace/racePlan
 * comments) held Type B to be ONE pooled at-large STV race electing every
 * seat together. The operator reversed that on 2026-07-29: Type B is
 *   one at-large race PER DIRECT CHILD (ungrouped),
 *   or one at-large race PER CLUMP when a size ceiling forces grouping.
 * Population must never dominate equal representation, and a single pooled
 * race let it. Each child / clump elects its OWN rep_floor seats from its OWN
 * residents. The seat MATH was already correct (Niue → 5 clumps × 2 = 10);
 * only the race GROUPING changes.
 *
 * Two schema facts stood in the way, both fixed here (additive-only, REAL
 * dated 2026-07-29 after the grouping trio at ...150000 which this references):
 *
 *  1. election_races had no way to say WHICH clump a race belongs to. We add
 *     `type_b_panel_id` → legislature_type_b_panels. A per-CHILD race needs no
 *     new column (its `jurisdiction_id` already IS the child, and RaceFootprint
 *     already enfranchises that jurisdiction's residents); a per-CLUMP race
 *     carries the panel id, and RaceFootprint LEFT JOINs the panel's member
 *     jurisdictions to enfranchise the UNION of the clump's constituents.
 *
 *  2. `election_races_one_at_large_per_kind` forbade more than ONE at-large
 *     race per (election, seat_kind) — precisely the pooled shape we are
 *     leaving. It is RELAXED for type_b only (every other kind keeps the strict
 *     one-per-kind rule, byte-identical), and type_b gets its own uniqueness
 *     keyed on the UNIT (panel when clumped, else the child jurisdiction) so
 *     duplicates are still impossible while multiplicity is allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. The clump key. Nullable: only per-CLUMP races carry it; per-child
        //    and every non-type_b race leave it NULL. nullOnDelete keeps a
        //    historical election_race even if its panel is later hard-removed
        //    (panels normally soft-delete on re-grouping, so this rarely fires).
        Schema::table('election_races', function (Blueprint $table) {
            $table->uuid('type_b_panel_id')->nullable()->after('district_id');
            $table->foreign('type_b_panel_id')
                ->references('id')->on('legislature_type_b_panels')
                ->nullOnDelete();
            $table->index('type_b_panel_id');
        });

        // 2a. Relax the pooled-race enforcer: keep strict one-at-large-per-kind
        //     for every kind EXCEPT type_b (unchanged behaviour for type_a,
        //     single, exec_committee, judicial_group and org board races).
        DB::statement('DROP INDEX IF EXISTS election_races_one_at_large_per_kind');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX election_races_one_at_large_per_kind
                ON election_races (election_id, seat_kind)
             WHERE district_id IS NULL AND seat_kind <> 'type_b'
        SQL);

        // 2b. type_b may now have MANY at-large races per election — one per
        //     child (ungrouped) or one per clump (grouped) — but never two for
        //     the same unit. The unit is the panel when clumped, else the child
        //     jurisdiction; COALESCE picks whichever identifies the race.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX election_races_type_b_unit_unique
                ON election_races (election_id, COALESCE(type_b_panel_id, jurisdiction_id))
             WHERE district_id IS NULL AND seat_kind = 'type_b'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS election_races_type_b_unit_unique');
        DB::statement('DROP INDEX IF EXISTS election_races_one_at_large_per_kind');

        // Restore the original pooled-race enforcer verbatim (baseline shape).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX election_races_one_at_large_per_kind
                ON election_races (election_id, seat_kind)
             WHERE district_id IS NULL
        SQL);

        Schema::table('election_races', function (Blueprint $table) {
            $table->dropForeign(['type_b_panel_id']);
            $table->dropIndex(['type_b_panel_id']);
            $table->dropColumn('type_b_panel_id');
        });
    }
};
