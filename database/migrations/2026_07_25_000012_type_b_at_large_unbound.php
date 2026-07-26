<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE 5–9 BAND IS A DISTRICT RULE — it does not bind an at-large Type B race.
 * (Operator ruling, SETTLED 2026-07-26. CLAUDE.md "Bicameral Support
 * (Article V §3)". Do not re-derive it.)
 *
 * `election_races_seats_check` bound BOTH legislative kinds to 1–9. That was
 * right for `type_a` and wrong for `type_b`:
 *
 *   type_a — DISTRICTED. A chamber above nine must subdivide, and each
 *            district race seats 5–9. The band is exactly this rule, so it
 *            stays, unchanged.
 *   type_b — AT-LARGE. The jurisdiction IS the district, so Type B is ONE STV
 *            race electing all its seats together, however many. San Marino
 *            elects 27 in a single race. There is no district to bound.
 *
 * The schema already contained the proof that nine was never a property of STV
 * races here: `exec_committee` and `judicial_group` are both `>= 5` with NO
 * upper bound, and always have been. Type B now joins them.
 *
 * The only bound on Type B is the TYPE A TOTAL, enforced upstream by
 * TypeBSeatLadder (seats-per-constituent stepping 5 → 4 → 3 → 2). Only when
 * two-per-constituent still overflows is a chamber flagged
 * `type_b_needs_districting`, and the remedy there is grouping whole
 * constituent jurisdictions into shared panels — never a cut through this race.
 *
 * Splitting a chamber into smaller races is explicitly NOT the alternative:
 * STV elects N seats in one race, and reducing magnitude is precisely what
 * destroys the proportionality the method exists to deliver.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE election_races DROP CONSTRAINT IF EXISTS election_races_seats_check');

        DB::statement(<<<'SQL'
            ALTER TABLE election_races
              ADD CONSTRAINT election_races_seats_check CHECK (
                     (seat_kind = 'type_a'         AND seats >= 1 AND seats <= 9)
                  OR (seat_kind = 'type_b'         AND seats >= 1)
                  OR (seat_kind = 'single'         AND seats = 1)
                  OR (seat_kind = 'exec_committee' AND seats >= 5)
                  OR (seat_kind = 'judicial_group' AND seats >= 5)
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE election_races DROP CONSTRAINT IF EXISTS election_races_seats_check');

        DB::statement(<<<'SQL'
            ALTER TABLE election_races
              ADD CONSTRAINT election_races_seats_check CHECK (
                     (seat_kind IN ('type_a', 'type_b') AND seats >= 1 AND seats <= 9)
                  OR (seat_kind = 'single'         AND seats = 1)
                  OR (seat_kind = 'exec_committee' AND seats >= 5)
                  OR (seat_kind = 'judicial_group' AND seats >= 5)
              )
        SQL);
    }
};
