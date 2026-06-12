<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-11 — evolve `legislature_members`: the seating substrate (R-09
 * derivation source) (PHASE_B_DESIGN_schema_lifecycle §A B-11).
 *
 * The table is a verified-empty skeleton (guarded at runtime below).
 *
 *  - `status` recut to the seating machine: elected → seated (oath
 *    F-LEG-001 is Phase C — B's certification seats with 'elected') →
 *    vacated | removed | term_ended. Default moves 'active' → 'elected'
 *    (the state certification writes).
 *  - `vote_count` dropped: unsignedSmallInteger overflows at Earth scale
 *    and duplicates race_results; superseded by `vote_share_norm` (the
 *    normalized-quota share, copied from race_results at seating — the
 *    committee tie-break input).
 *  - The full UNIQUE (legislature_id, user_id) becomes PARTIAL (current
 *    statuses only) — it blocked re-election history; history rows keep
 *    their terms.
 *  - `district_id` gains its real FK → legislature_districts (the original
 *    comment said "future" — future is now).
 *  - `home_jurisdiction_id`: the member's association at election time
 *    (countback/relocation provenance). No FK — dev reseeds jurisdictions.
 *  - `seat_type` char(1) stays ('a'/'b' — maps to election_races.seat_kind
 *    'type_a'/'type_b' via the LegislatureMember model).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: this evolve assumes the skeleton is empty (same pattern as
        // B-3 / the 2026_06_12_000002 users rebuild).
        $count = (int) DB::table('legislature_members')->count();
        if ($count > 0) {
            throw new RuntimeException(
                "legislature_members holds {$count} row(s) — this evolve migration assumes an " .
                'empty skeleton (it recuts status and the unique). Migrate its rows manually first.'
            );
        }

        // ── 1. add seating columns ───────────────────────────────────────────
        Schema::table('legislature_members', function (Blueprint $table) {
            $table->smallInteger('seat_no')->nullable();

            $table->uuid('elected_in_race_id')->nullable();
            $table->foreign('elected_in_race_id')->references('id')->on('election_races')->nullOnDelete();

            $table->uuid('term_id')->nullable();
            $table->foreign('term_id')->references('id')->on('terms')->nullOnDelete();

            // Normalized-quota share — committee tie-break input.
            $table->decimal('vote_share_norm', 8, 4)->nullable();

            // Certification sets status='elected'; Phase C's oath (F-LEG-001)
            // flips to 'seated' and stamps this.
            $table->timestampTz('seated_at')->nullable();

            // Association at election time. No FK — dev reseeds jurisdictions.
            $table->uuid('home_jurisdiction_id')->nullable();
        });

        // ── 2. drop vote_count ───────────────────────────────────────────────
        Schema::table('legislature_members', function (Blueprint $table) {
            $table->dropColumn('vote_count');
        });

        // ── 3. recut status ──────────────────────────────────────────────────
        DB::statement("ALTER TABLE legislature_members ALTER COLUMN status SET DEFAULT 'elected'");
        DB::statement(
            "ALTER TABLE legislature_members ADD CONSTRAINT legislature_members_status_check " .
            "CHECK (status IN ('elected', 'seated', 'vacated', 'removed', 'term_ended'))"
        );

        // ── 4. replace the full unique with the one-current partial ─────────
        Schema::table('legislature_members', function (Blueprint $table) {
            $table->dropUnique(['legislature_id', 'user_id']);
        });
        DB::statement(
            'CREATE UNIQUE INDEX legislature_members_one_current ON legislature_members ' .
            "(legislature_id, user_id) WHERE status IN ('elected', 'seated') AND deleted_at IS NULL"
        );

        // ── 5. district_id gains its real FK ─────────────────────────────────
        DB::statement(
            'ALTER TABLE legislature_members ADD CONSTRAINT legislature_members_district_id_foreign ' .
            'FOREIGN KEY (district_id) REFERENCES legislature_districts(id) ON DELETE SET NULL'
        );
    }

    /**
     * Full reversal to the post-apportionment-cleanup skeleton shape —
     * lossless because both shapes are only valid while the table is empty.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE legislature_members DROP CONSTRAINT IF EXISTS legislature_members_district_id_foreign');

        DB::statement('DROP INDEX IF EXISTS legislature_members_one_current');
        Schema::table('legislature_members', function (Blueprint $table) {
            $table->unique(['legislature_id', 'user_id']);
        });

        DB::statement('ALTER TABLE legislature_members DROP CONSTRAINT IF EXISTS legislature_members_status_check');
        DB::statement("ALTER TABLE legislature_members ALTER COLUMN status SET DEFAULT 'active'");

        Schema::table('legislature_members', function (Blueprint $table) {
            $table->unsignedSmallInteger('vote_count')->nullable();
        });

        Schema::table('legislature_members', function (Blueprint $table) {
            $table->dropForeign(['elected_in_race_id']);
            $table->dropForeign(['term_id']);
            $table->dropColumn([
                'seat_no',
                'elected_in_race_id',
                'term_id',
                'vote_share_norm',
                'seated_at',
                'home_jurisdiction_id',
            ]);
        });
    }
};
