<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index the legislatures.parent_legislature_id self-FK (found live
 * 2026-07-18): without it, every DELETE on legislatures runs the FK's
 * referential action as a per-row SEQUENTIAL SCAN of the whole table —
 * O(n²) at True-All-Scale row counts (a 951k-row wipe ran >1 h before the
 * statement was cancelled; with the index the same work is minutes).
 * IF NOT EXISTS: the live box received this index by hand mid-incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS legislatures_parent_legislature_id_index
                       ON legislatures (parent_legislature_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS legislatures_parent_legislature_id_index');
    }
};
