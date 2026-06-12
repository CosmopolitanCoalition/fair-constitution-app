<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-3 — evolve `elections` to ESM-03 (PHASE_B_DESIGN_schema_lifecycle §A B-3).
 *
 * The original 2026_01_01_000005 table is an EMPTY SKELETON (zero writers
 * anywhere in app/ — verified, and guarded at runtime below): in-place
 * rename/drops are safe.
 *
 *  - `type` → `kind`, recut to the full Phase B+ enum (only general/special
 *    writable in B — engine-gated).
 *  - `status` recut to the ESM-03 machine (`audit_rerun` is the ESM's
 *    [Recount] under the no-hand-count reframing).
 *  - Per-race data (seats, quota, district, office, totals) moves to
 *    `election_races` (B-4); referendum fields return as
 *    `referendum_questions` in Phase C. `voting_method` STAYS — the
 *    whole-election snapshot of the amendable setting.
 *  - `results_certified_at` is dropped too (not in the design's drop list
 *    but superseded verbatim by the new `certified_at timestamptz` —
 *    keeping both would leave a dead duplicate; table is empty).
 *  - CLK-18 window + schedule columns are timestamptz (UTC).
 *  - `prior_election_id` chains cycles: certification of N opens approval
 *    of N+1. `triggered_by_timer_id` records clock provenance — elections
 *    fire from clocks, never official discretion.
 *  - `ballot_key_wrapped` (design §B.5.2): per-election sodium data key,
 *    generated at ranked_open, wrapped by the Laravel app key. This is
 *    confidentiality against DB exfiltration, NOT against the server
 *    operator — production custody is on the cryptographer-review list.
 *  - `vacancy_id` gets its FK in B-10 (forward ref).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: this evolve assumes the skeleton is empty (same pattern as
        // the 2026_06_12_000002 users rebuild).
        $count = (int) DB::table('elections')->count();
        if ($count > 0) {
            throw new RuntimeException(
                "elections holds {$count} row(s) — this evolve migration assumes an empty " .
                'skeleton (it renames/drops columns in place). Migrate its rows manually first.'
            );
        }

        // ── 1. rename type → kind (index dropped first, recreated below) ────
        Schema::table('elections', function (Blueprint $table) {
            $table->dropIndex('elections_type_index');
        });
        Schema::table('elections', function (Blueprint $table) {
            $table->renameColumn('type', 'kind');
        });

        // ── 2. drop per-race / referendum / legacy schedule columns ─────────
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn([
                'nomination_opens_on',
                'nomination_closes_on',
                'voting_opens_on',
                'voting_closes_on',
                'seats_to_fill',
                'droop_quota',
                'district_id',
                'office_type',
                'office_id',
                'total_valid_votes',
                'legislative_act_id',
                'referendum_question',
                'referendum_requires_supermajority',
                'referendum_passed',
                'results_certified_at',
            ]);
        });

        // ── 3. add ESM-03 columns ────────────────────────────────────────────
        Schema::table('elections', function (Blueprint $table) {
            // Body being filled.
            $table->uuid('legislature_id')->nullable();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->nullOnDelete();

            // Map snapshot races were generated from; NULL = at-large.
            $table->uuid('district_map_id')->nullable();
            $table->foreign('district_map_id')->references('id')->on('legislature_district_maps')->restrictOnDelete();

            // CLK-18 window + schedule (UTC).
            $table->timestampTz('approval_opens_at')->nullable();
            $table->timestampTz('finalist_cutoff_at')->nullable();
            $table->timestampTz('ranked_opens_at')->nullable();
            $table->timestampTz('ranked_closes_at')->nullable();
            $table->timestampTz('certified_at')->nullable();

            // Cycle chain: certification of N opens approval of N+1.
            $table->uuid('prior_election_id')->nullable();
            $table->foreign('prior_election_id')->references('id')->on('elections')->nullOnDelete();

            // Clock provenance.
            $table->uuid('triggered_by_timer_id')->nullable();
            $table->foreign('triggered_by_timer_id')->references('id')->on('clock_timers')->nullOnDelete();

            // Special elections point at their vacancy; FK added in B-10.
            $table->uuid('vacancy_id')->nullable();

            // Per-election wrapped ballot data key (design §B.5.2).
            $table->text('ballot_key_wrapped')->nullable();
        });

        // ── 4. make election_board_id real ───────────────────────────────────
        Schema::table('elections', function (Blueprint $table) {
            $table->foreign('election_board_id')->references('id')->on('election_boards')->nullOnDelete();
        });

        // ── 5. recut CHECKs ──────────────────────────────────────────────────
        DB::statement(
            "ALTER TABLE elections ADD CONSTRAINT elections_kind_check CHECK (kind IN (" .
            "'general', 'special', 'executive', 'judicial', 'referendum', " .
            "'org_board_owner', 'org_board_worker', 'restoration'))"
        );
        DB::statement(
            "ALTER TABLE elections ADD CONSTRAINT elections_status_check CHECK (status IN (" .
            "'scheduled', 'approval_open', 'finalist_cutoff', 'ranked_open', 'voting_closed', " .
            "'tabulating', 'certified', 'audit_rerun', 'final', 'cancelled'))"
        );

        // ── 6. new indexes ───────────────────────────────────────────────────
        Schema::table('elections', function (Blueprint $table) {
            $table->index(['legislature_id', 'status']);
            $table->index(['kind', 'status']);
            $table->index('finalist_cutoff_at');
            $table->index('ranked_closes_at');
        });

        // Forward ref declared in B-1: terms.source_election_id → elections.
        DB::statement(
            'ALTER TABLE terms ADD CONSTRAINT terms_source_election_id_foreign ' .
            'FOREIGN KEY (source_election_id) REFERENCES elections(id) ON DELETE SET NULL'
        );
    }

    /**
     * Full reversal back to the 2026_01_01_000005 skeleton shape — lossless
     * because both shapes are only valid while the table is empty.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_source_election_id_foreign');

        Schema::table('elections', function (Blueprint $table) {
            $table->dropIndex(['legislature_id', 'status']);
            $table->dropIndex(['kind', 'status']);
            $table->dropIndex(['finalist_cutoff_at']);
            $table->dropIndex(['ranked_closes_at']);
        });

        DB::statement('ALTER TABLE elections DROP CONSTRAINT IF EXISTS elections_kind_check');
        DB::statement('ALTER TABLE elections DROP CONSTRAINT IF EXISTS elections_status_check');

        Schema::table('elections', function (Blueprint $table) {
            $table->dropForeign(['legislature_id']);
            $table->dropForeign(['district_map_id']);
            $table->dropForeign(['prior_election_id']);
            $table->dropForeign(['triggered_by_timer_id']);
            $table->dropForeign(['election_board_id']);
            $table->dropColumn([
                'legislature_id',
                'district_map_id',
                'approval_opens_at',
                'finalist_cutoff_at',
                'ranked_opens_at',
                'ranked_closes_at',
                'certified_at',
                'prior_election_id',
                'triggered_by_timer_id',
                'vacancy_id',
                'ballot_key_wrapped',
            ]);
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->renameColumn('kind', 'type');
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->string('office_type')->nullable();
            $table->uuid('office_id')->nullable();
            $table->unsignedTinyInteger('seats_to_fill')->default(5);
            $table->unsignedSmallInteger('droop_quota')->nullable();
            $table->date('nomination_opens_on')->nullable();
            $table->date('nomination_closes_on')->nullable();
            $table->date('voting_opens_on')->nullable();
            $table->date('voting_closes_on')->nullable();
            $table->timestamp('results_certified_at')->nullable();
            $table->unsignedInteger('total_valid_votes')->nullable();
            $table->uuid('legislative_act_id')->nullable();
            $table->text('referendum_question')->nullable();
            $table->boolean('referendum_requires_supermajority')->default(false);
            $table->boolean('referendum_passed')->nullable();
            $table->uuid('district_id')->nullable();

            $table->index('type');
            $table->index('voting_opens_on');
            $table->index('voting_closes_on');
        });
    }
};
