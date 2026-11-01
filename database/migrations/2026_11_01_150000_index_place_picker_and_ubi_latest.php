<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TWO INDEXES FOR LISTS THAT SORT BIG TABLES (operator order 2026-11-01, slow-page hunt).
 *
 *  - jurisdictions (adm_level, name) where live: the place pickers and the jurisdiction list
 *    read "the first N by level then name". Without it Postgres sorted the whole table
 *    (12 seconds on the demo's spinning disk for the first 200 of 960,000 places).
 *  - ubi_disbursements (currency_id, ran_at desc): "the latest run for one currency" scanned
 *    and sorted every run (3.6 seconds).
 *
 * CONCURRENTLY: no write lock. That statement cannot run inside a transaction.
 * IF NOT EXISTS makes a second run a no-op.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS jurisdictions_live_level_name_idx ON jurisdictions (adm_level, name) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ubi_disbursements_currency_ran_at_idx ON ubi_disbursements (currency_id, ran_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS jurisdictions_live_level_name_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ubi_disbursements_currency_ran_at_idx');
    }
};
