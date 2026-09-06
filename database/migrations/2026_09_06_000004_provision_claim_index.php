<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE CLAIM INDEX (operator order 2026-09-06, the timing hunt). The Step 4
 * claim ordered by adm_level ASC, est_cost DESC — a mixed direction the old
 * (status, stage, adm_level, est_cost, legislature_id) index could not serve —
 * so every claim SORTED all ~889k pending rows (a 2.77s claim, versus 0.67s of
 * real unit work; the timings made it visible). This partial index matches the
 * claim order so topdown is a forward scan and bottomup the same index scanned
 * backward: an index descent, not a sort. Partial on status = 'pending' so it
 * shrinks as the run completes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX IF NOT EXISTS pl_claim_dir_idx
                       ON provision_ledger (stage, adm_level, est_cost DESC, legislature_id)
                       WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pl_claim_dir_idx');
    }
};
