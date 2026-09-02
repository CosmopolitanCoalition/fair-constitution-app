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
        // THE DATING GUARD (WoS virgin install, 2026-09-01): block_rank and
        // block_order are created by 2026_09_01_000001_ledger_single_home,
        // dated AFTER this file. On a virgin database this file runs first
        // and must not fail; the single-home migration creates the index
        // itself once the columns exist. On a box where the columns
        // pre-exist (the dev-era E box) this file still creates it.
        $cols = (int) DB::scalar("
            SELECT count(*) FROM information_schema.columns
             WHERE table_name = 'apportionment_ledger' AND column_name IN ('block_rank', 'block_order')
        ");
        if ($cols < 2) {
            return;
        }
        DB::statement('CREATE INDEX IF NOT EXISTS al_block_priority_idx
                       ON apportionment_ledger (block_rank, block_order)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS al_block_priority_idx');
    }
};
