<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TWO PILES BY CLASS (operator order 2026-09-02): autoscale_runs.leaf_lanes
 * (N lanes prefer line-split scopes, the rest composites, spill both ways)
 * and the two partial indexes that keep a class pop an index descent.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE autoscale_runs ADD COLUMN IF NOT EXISTS leaf_lanes smallint');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS als_status_walk_leaf_idx
                       ON apportionment_ledger_scopes (status, walk_position) WHERE is_leaf IS TRUE');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS als_status_walk_comp_idx
                       ON apportionment_ledger_scopes (status, walk_position) WHERE COALESCE(is_leaf, false) = false');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS als_status_walk_leaf_idx');
        DB::statement('DROP INDEX IF EXISTS als_status_walk_comp_idx');
        DB::statement('ALTER TABLE autoscale_runs DROP COLUMN IF EXISTS leaf_lanes');
    }
};
