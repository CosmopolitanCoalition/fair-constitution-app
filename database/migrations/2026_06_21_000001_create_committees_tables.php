<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C (chamber ops §C.1) — `committees` (I-COM) + `committee_seats`
 * (ESM-09) + `committee_preferences` + `committee_meetings` +
 * `committee_reports`, plus `chamber_vote_proposals` (see below).
 *
 * Cross-scope references (chamber_votes / laws / bills / public_records are
 * the sibling votes-laws migration set, 2026_06_20_*): all carried as plain
 * uuid columns with NO FK — the exact Phase B precedent
 * (appointments.consent_vote_id, election_boards.created_by_act_id).
 * public_records is append-only by design, so a hard FK is undesirable
 * there anyway. ONE conditional exception: when `bills` already exists at
 * run time, this migration adds the `bills.committee_id → committees` FK
 * the votes-laws design (C-6) delegates to "the sibling's committees
 * migration"; on a fresh database the 2026_06_20_* set sorts first, so the
 * FK is always present there.
 *
 * `chamber_vote_proposals` is this scope's proposal store: an act-creating
 * vote (F-LEG-009/012/013/032/033) needs its payload (name/seats/nominees/
 * law text) to survive between vote OPEN and vote ADOPTION across many
 * member cast filings, and `chamber_votes` carries no payload column. The
 * institution row itself is created only on adoption (same posture as
 * emergency_powers in the sibling design) — a failed vote leaves a
 * `rejected` proposal + the failed vote, never a half-born institution.
 *
 * `committee_seats` deliberately has no soft deletes: `vacated_at` /
 * `vacated_reason` ARE the lifecycle (a seat row is history, like
 * session_attendance) — documented soft-delete exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── committees (I-COM) ──────────────────────────────────────────────
        Schema::create('committees', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            $table->string('name');
            $table->text('purpose')->nullable();

            $table->smallInteger('seats');

            // Bicameral kind split (Art. V §3 mirror); both NULL = unicameral.
            $table->smallInteger('type_a_seats')->nullable();
            $table->smallInteger('type_b_seats')->nullable();

            // Cross-scope soft refs (no FK — see file docblock).
            $table->uuid('created_by_vote_id')->nullable();
            $table->uuid('created_by_law_id')->nullable();

            $table->uuid('chair_member_id')->nullable();
            $table->foreign('chair_member_id')->references('id')->on('legislature_members')->nullOnDelete();

            $table->uuid('alternate_member_id')->nullable();
            $table->foreign('alternate_member_id')->references('id')->on('legislature_members')->nullOnDelete();

            $table->string('status', 12)->default('created');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('legislature_id');
        });

        DB::statement('ALTER TABLE committees ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE committees ADD CONSTRAINT committees_status_check " .
            "CHECK (status IN ('created', 'seated', 'dissolved'))"
        );
        DB::statement('ALTER TABLE committees ADD CONSTRAINT committees_seats_check CHECK (seats >= 1)');
        // Kind split is all-or-nothing and must total the seat count.
        DB::statement(
            'ALTER TABLE committees ADD CONSTRAINT committees_kind_split_check CHECK (' .
            '(type_a_seats IS NULL AND type_b_seats IS NULL) OR ' .
            '(type_a_seats IS NOT NULL AND type_b_seats IS NOT NULL AND type_a_seats + type_b_seats = seats))'
        );

        // ── committee_seats (ESM-09) ────────────────────────────────────────
        Schema::create('committee_seats', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('committee_id');
            $table->foreign('committee_id')->references('id')->on('committees')->cascadeOnDelete();

            $table->uuid('member_id');
            $table->foreign('member_id')->references('id')->on('legislature_members')->restrictOnDelete();

            // NULL = unicameral (unsplit) seat.
            $table->string('seat_kind', 8)->nullable();

            $table->string('status', 12)->default('assigned');
            $table->string('assigned_via', 16)->nullable();

            // The preference depth the algorithm honored (NULL = exhaustion guard).
            $table->smallInteger('preference_rank_honored')->nullable();

            $table->timestampTz('seated_at')->nullable();
            $table->timestampTz('vacated_at')->nullable();
            $table->string('vacated_reason', 24)->nullable();

            $table->timestampsTz();

            $table->index('member_id');
        });

        DB::statement('ALTER TABLE committee_seats ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE committee_seats ADD CONSTRAINT committee_seats_status_check " .
            "CHECK (status IN ('allocated', 'assigned', 'tie_broken', 'seated', 'vacated'))"
        );
        DB::statement(
            "ALTER TABLE committee_seats ADD CONSTRAINT committee_seats_kind_check " .
            "CHECK (seat_kind IS NULL OR seat_kind IN ('type_a', 'type_b'))"
        );
        DB::statement(
            "ALTER TABLE committee_seats ADD CONSTRAINT committee_seats_assigned_via_check " .
            "CHECK (assigned_via IS NULL OR assigned_via IN ('algorithm', 'tie_break', 'whole_house_rcv'))"
        );
        // A member never holds two live seats on one committee (C.4 backstop).
        DB::statement(
            'CREATE UNIQUE INDEX committee_seats_one_live ON committee_seats ' .
            '(committee_id, member_id) WHERE vacated_at IS NULL'
        );

        // ── committee_preferences (F-LEG-010) ───────────────────────────────
        Schema::create('committee_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->cascadeOnDelete();

            $table->uuid('member_id');
            $table->foreign('member_id')->references('id')->on('legislature_members')->cascadeOnDelete();

            // Ordered committee ids, most preferred first.
            $table->jsonb('rankings')->default('[]');

            $table->timestampTz('submitted_at');

            $table->timestampsTz();

            $table->unique(['legislature_id', 'member_id']);
        });

        DB::statement('ALTER TABLE committee_preferences ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // ── committee_meetings (F-CHR-001/002) ──────────────────────────────
        Schema::create('committee_meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('committee_id');
            $table->foreign('committee_id')->references('id')->on('committees')->cascadeOnDelete();

            $table->uuid('called_by_member_id');
            $table->foreign('called_by_member_id')->references('id')->on('legislature_members')->restrictOnDelete();

            $table->timestampTz('scheduled_for');
            $table->jsonb('agenda')->default('[]');

            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('adjourned_at')->nullable();

            $table->string('status', 12)->default('scheduled');

            // public_records.id (uuid) — soft ref, append-only target.
            $table->uuid('minutes_record_id')->nullable();

            $table->timestampsTz();

            $table->index('committee_id');
        });

        DB::statement('ALTER TABLE committee_meetings ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE committee_meetings ADD CONSTRAINT committee_meetings_status_check " .
            "CHECK (status IN ('scheduled', 'open', 'adjourned'))"
        );

        // ── committee_reports (F-CHR-004) ───────────────────────────────────
        Schema::create('committee_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('committee_id');
            $table->foreign('committee_id')->references('id')->on('committees')->cascadeOnDelete();

            // bills is sibling scope — soft ref; conditional FK below.
            $table->uuid('bill_id')->nullable();

            $table->uuid('filed_by_member_id');
            $table->foreign('filed_by_member_id')->references('id')->on('legislature_members')->restrictOnDelete();

            // public_records.id — the report body lives on the public record.
            $table->uuid('report_record_id');

            $table->timestampsTz();

            $table->index('committee_id');
        });

        DB::statement('ALTER TABLE committee_reports ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // ── chamber_vote_proposals (this scope's proposal store) ────────────
        Schema::create('chamber_vote_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->cascadeOnDelete();

            $table->string('proposal_kind', 32);

            // chamber_votes.id — soft ref (sibling scope), set at vote open.
            $table->uuid('vote_id')->nullable()->unique();

            $table->jsonb('payload')->default('{}');

            $table->uuid('proposed_by_member_id')->nullable();
            $table->foreign('proposed_by_member_id')->references('id')->on('legislature_members')->nullOnDelete();

            $table->string('status', 12)->default('open');
            $table->timestampTz('decided_at')->nullable();

            // What the adoption created (committees / election_boards /
            // admin_offices / laws row) — polymorphic soft ref.
            $table->string('result_type', 40)->nullable();
            $table->uuid('result_id')->nullable();

            $table->timestampsTz();

            $table->index(['legislature_id', 'proposal_kind', 'status']);
        });

        DB::statement('ALTER TABLE chamber_vote_proposals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE chamber_vote_proposals ADD CONSTRAINT chamber_vote_proposals_kind_check " .
            "CHECK (proposal_kind IN ('committee_creation', 'election_board_creation', " .
            "'admin_office_creation', 'rules_of_order', 'ethics_code'))"
        );
        DB::statement(
            "ALTER TABLE chamber_vote_proposals ADD CONSTRAINT chamber_vote_proposals_status_check " .
            "CHECK (status IN ('open', 'adopted', 'rejected'))"
        );

        // ── Conditional cross-scope FKs (see file docblock) ─────────────────
        if (Schema::hasTable('bills')) {
            if (Schema::hasColumn('bills', 'committee_id')) {
                DB::statement(
                    'ALTER TABLE bills ADD CONSTRAINT bills_committee_id_foreign ' .
                    'FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE SET NULL'
                );
            }

            DB::statement(
                'ALTER TABLE committee_reports ADD CONSTRAINT committee_reports_bill_id_foreign ' .
                'FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bills')) {
            DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_committee_id_foreign');
        }

        Schema::dropIfExists('chamber_vote_proposals');
        Schema::dropIfExists('committee_reports');
        Schema::dropIfExists('committee_meetings');
        Schema::dropIfExists('committee_preferences');
        Schema::dropIfExists('committee_seats');
        Schema::dropIfExists('committees');
    }
};
