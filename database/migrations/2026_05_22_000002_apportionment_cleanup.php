<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apportionment cleanup — drop dead columns + faction surface.
 *
 * Why this migration exists.
 *   In preparation for standing up multiple legislatures, three pieces of
 *   schema are removed:
 *
 *     1. `jurisdictions.type_a_apportioned` / `type_b_apportioned` —
 *        modeled apportionment as a single int per polygon, which breaks
 *        the moment a jurisdiction with > 9 seat entitlement is split
 *        across multiple districts (most non-leaf jurisdictions).
 *        Apportionment data already exists at the correct level on
 *        `legislature_districts.seats`. `type_b_apportioned` has zero
 *        writers anywhere in the codebase — pure dead weight.
 *
 *     2. `legislature_faction_registrations` (table) and
 *        `legislature_members.primary_faction_id` /
 *        `additional_faction_ids` (columns) — the "multi-faction
 *        committee proportionality" design was abandoned. Committee
 *        assignment is now rank-choice across all members with
 *        normalized vote-share tiebreak. Endorsements continue to live
 *        on `endorsements` (polymorphic, any org or user). The table
 *        had 0 rows and 0 callers at rip time; the columns had 0
 *        readers/writers outside their migration definitions.
 *
 * What this migration does.
 *   - DROP COLUMN jurisdictions.type_a_apportioned
 *   - DROP COLUMN jurisdictions.type_b_apportioned
 *   - DROP COLUMN legislature_members.primary_faction_id (incl. FK + index)
 *   - DROP COLUMN legislature_members.additional_faction_ids
 *   - DROP TABLE legislature_faction_registrations
 *
 * Rollback.
 *   `down()` is best-effort. Re-adding the columns is straightforward
 *   but the values are gone forever (no live writers for the faction
 *   columns; `type_a_apportioned` can be re-derived on demand from
 *   district-level data once districts exist). Code rollback is
 *   git-revert clean — no data-migration shenanigans.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. jurisdictions: drop per-jurisdiction apportioned columns ──
        // Apportionment lives on legislature_districts.seats; carrying a
        // single int per jurisdiction is misleading once a jurisdiction is
        // composite-split.
        Schema::table('jurisdictions', function ($table) {
            $table->dropColumn(['type_a_apportioned', 'type_b_apportioned']);
        });

        // ── 2. legislature_members: drop faction columns ─────────────────
        // The FK constraint on primary_faction_id → organizations.id is
        // dropped first; Laravel's dropForeign uses the canonical name.
        Schema::table('legislature_members', function ($table) {
            $table->dropForeign('legislature_members_primary_faction_id_foreign');
            $table->dropIndex('legislature_members_primary_faction_id_index');
            $table->dropColumn(['primary_faction_id', 'additional_faction_ids']);
        });

        // ── 3. Drop the faction registrations table ──────────────────────
        Schema::dropIfExists('legislature_faction_registrations');
    }

    public function down(): void
    {
        // Best-effort no-op. See class docstring.
        //
        // To resurrect the faction surface, you would need to:
        //   1. Recreate `legislature_faction_registrations` (see
        //      database/migrations/2026_01_01_000007_create_legislature_faction_registrations_table.php).
        //   2. Re-add `primary_faction_id` + `additional_faction_ids` to
        //      `legislature_members` (see
        //      database/migrations/2026_01_01_000006_create_legislature_members_table.php).
        //   3. Re-add the per-jurisdiction apportioned columns to
        //      `jurisdictions` — but those values were never derivable
        //      from district-level data automatically; they had to be
        //      written by the auto-composer, which no longer does so.
        // None of the dropped data can be reconstructed from this
        // migration alone — `up()` is intentionally one-way.
    }
};
