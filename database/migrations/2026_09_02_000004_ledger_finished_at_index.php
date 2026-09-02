<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE LEDGER FINISHED-AT INDEX (fix 7, 2026-09-02, the Step-3 poll load).
 *
 * The dashboard's 10-minute rate query reads apportionment_ledger by
 * finished_at alone (SetupController::autoscaleProgress). The only index
 * that held finished_at led with map_status (al_status_finished_idx), so
 * the planner walked that whole index on every poll: cost ~17.7k on the
 * 1.03M-row ledger (EXPLAIN on the live box, 2026-09-02). This partial
 * index turns that read into a range over the finished rows only.
 *
 * apportionment_ledger_scopes needs no new index. Its rate query filters
 * status = 'done' AND finished_at > t, which als_status_finished_idx
 * (status, finished_at) already serves as an index-only scan (cost 2.65).
 *
 * Plain CREATE INDEX, not CONCURRENTLY: Laravel runs a migration inside a
 * transaction and CREATE INDEX CONCURRENTLY refuses to run in one. The
 * build holds a SHARE lock on the ledger for its duration (seconds on a
 * 1M-row table). Apply it while the autoscale run is halted. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE INDEX IF NOT EXISTS al_finished_at_idx
                ON apportionment_ledger (finished_at)
             WHERE finished_at IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS al_finished_at_idx');
    }
};
