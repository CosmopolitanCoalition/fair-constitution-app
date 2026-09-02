<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE CLAIM LADDER INDEXES (operator's lane-time audit 2026-09-02): the
 * finalize rung's ORDER BY position LIMIT 1 walked al_position_idx past
 * 940,324 non-running headers on EVERY claim (5 s, 35% of lane time); the
 * childless-child count (childLayerIsInert) scanned the GIST geometry index
 * for NULLs (10 s under load); the level law's children population sum
 * fetched every child's heap row. Built CONCURRENTLY: no lock on a live box.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS al_finalize_pos_idx
                       ON apportionment_ledger (position) WHERE kind = 'sweep' AND map_status = 'running'");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS jurisdictions_parent_nogeom_idx
                       ON jurisdictions (parent_id) WHERE geom IS NULL AND deleted_at IS NULL');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS jurisdictions_parent_pop_idx
                       ON jurisdictions (parent_id) INCLUDE (population) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS al_finalize_pos_idx');
        DB::statement('DROP INDEX IF EXISTS jurisdictions_parent_nogeom_idx');
        DB::statement('DROP INDEX IF EXISTS jurisdictions_parent_pop_idx');
    }
};
