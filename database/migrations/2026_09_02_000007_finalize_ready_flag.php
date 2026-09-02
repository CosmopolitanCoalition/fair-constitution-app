<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE FINALIZE READY FLAG (window-1 audit 2026-09-02): the finalize rung
 * popped headers by re-checking every running header's scopes on every
 * claim. The flag is set by the scope close that empties a header (and by
 * the pump's once-a-minute safety net) and popped through a partial index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE apportionment_ledger ADD COLUMN IF NOT EXISTS finalize_ready boolean NOT NULL DEFAULT false');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS al_finalize_ready_idx
                       ON apportionment_ledger (position) WHERE finalize_ready AND kind = 'sweep' AND map_status = 'running'");
        DB::statement("
            UPDATE apportionment_ledger h SET finalize_ready = true
             WHERE h.kind = 'sweep' AND h.map_status = 'running'
               AND EXISTS (SELECT 1 FROM apportionment_ledger_scopes s WHERE s.legislature_id = h.legislature_id)
               AND NOT EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                WHERE s.legislature_id = h.legislature_id AND s.status IN ('pending', 'running'))
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS al_finalize_ready_idx');
        DB::statement('ALTER TABLE apportionment_ledger DROP COLUMN IF EXISTS finalize_ready');
    }
};
