<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DROP THE REDUNDANT INDEXES (operator order 2026-11-01: efficiency on slower,
 * older hardware outranks the old additive-only migration rule).
 *
 * Every index below is either
 *   - a LEADING PREFIX of another index on the same table with the same
 *     predicate (the longer index answers every lookup the shorter one does), or
 *   - an exact duplicate of another index, or
 *   - a single boolean column whose partial indexes already cover its use.
 * Each one costs a write on every insert and update, and cache space the
 * lookups need. Found by comparing pg_index key lists on the demo box
 * (about 4 GB in all; residency_confirmations alone 2 GB).
 *
 * CONCURRENTLY: no write lock, so the app keeps serving. That statement cannot
 * run inside a transaction. IF EXISTS makes the migration safe on a box that
 * never had the index. Not reversible on purpose: rebuilding a prefix index
 * adds nothing the covering index lacks.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<string> */
    public const INDEXES = [
        // residency_confirmations (108 M rows)
        'residency_confirmations_jurisdiction_active_idx', // prefix of residency_active_jurisdiction_user_idx
        'residency_confirmations_is_active_index',         // one boolean column; the partial indexes cover it
        // prefix of a longer index with the same predicate
        'ubi_receipts_account_id_index',
        'sim_items_claim_idx',
        'approval_standings_race_id_as_of_date_index',
        'organizations_jurisdiction_id_index',
        'judicial_seats_judiciary_id_index',
        'executive_members_executive_id_index',
        'elections_jurisdiction_id_index',
        'legislature_district_maps_legislature_id_index',
        'legislatures_jurisdiction_id_index',
        'executives_jurisdiction_id_index',
        'org_ownership_stakes_open_by_org',
        'legislature_type_b_panel_jurisdictions_panel_id_index',
        // exact duplicate
        'election_board_members_election_board_id_index',  // = election_board_members_board_id_index
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
        }
    }

    public function down(): void
    {
        // Redundant by construction. Nothing to restore.
    }
};
