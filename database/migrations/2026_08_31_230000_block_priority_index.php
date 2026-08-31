<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE BLOCK PRIORITY INDEX (operator ruling 2026-08-31, "all 13 lanes at
 * all times"): claims now ORDER by (block_rank, block_order) first, so the
 * header side of every claim sort gets a covering index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS al_block_priority_idx
                       ON apportionment_ledger (block_rank, block_order)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS al_block_priority_idx');
    }
};
