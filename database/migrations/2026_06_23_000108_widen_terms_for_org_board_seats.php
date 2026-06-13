<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-O8 item 3 (PHASE_D_DESIGN_organizations §A) — terms CHECK widening
 * for org-board seats. (Items 1–2 — elections.board_id + the vote_casts
 * board widening — shipped with the exec builder's boards migration
 * 2026_06_23_000002 per the "first lander ships it" contract.)
 *
 *  - office_kind + 'board_seat': elected owner/worker board seats
 *    ('board_governor' already exists for the appointment pipeline);
 *  - term_class + 'org_cycle': R-26/27/28 terms run on the board cycle
 *    (ends_on = starts + boards.cycle_months — WF-ORG-05; write-once
 *    like every term, no ends_on mutation API).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_office_kind_check');
        DB::statement(
            "ALTER TABLE terms ADD CONSTRAINT terms_office_kind_check CHECK (office_kind IN (" .
            "'legislature_seat', 'executive_seat', 'judicial_seat', 'election_board_member', " .
            "'board_governor', 'board_seat', 'admin_staff', 'civil_officer'))"
        );

        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_term_class_check');
        DB::statement(
            "ALTER TABLE terms ADD CONSTRAINT terms_term_class_check " .
            "CHECK (term_class IN ('lockstep', 'civil_appointment', 'org_cycle'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_term_class_check');
        DB::statement(
            "ALTER TABLE terms ADD CONSTRAINT terms_term_class_check " .
            "CHECK (term_class IN ('lockstep', 'civil_appointment'))"
        );

        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_office_kind_check');
        DB::statement(
            "ALTER TABLE terms ADD CONSTRAINT terms_office_kind_check CHECK (office_kind IN (" .
            "'legislature_seat', 'executive_seat', 'judicial_seat', 'election_board_member', " .
            "'board_governor', 'admin_staff', 'civil_officer'))"
        );
    }
};
