<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE SINGLES POP INDEX (operator confirmation 2026-08-31, the dispatch
 * order): claimSingles pops the leading edge of the stamped global header
 * position — this partial index makes that pop an index descent over only
 * the still-pending singles, never a sort of the 700k-header final block.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE INDEX IF NOT EXISTS al_singles_pop_idx
                ON apportionment_ledger (position)
             WHERE kind = 'single' AND map_status = 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS al_singles_pop_idx');
    }
};
