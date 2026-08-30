<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE BUDGET STAMP (operator order 2026-08-30, the Bayern 70/71 verdict):
 * one owner for the cascade. The materialization loop already holds each
 * child scope's lawful budget when it mints the scope row; it now stamps
 * it here, and the sweep draws to the stamp instead of recomputing under
 * whatever live state exists minutes later. Additive, real-dated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE autoscale_scopes ADD COLUMN IF NOT EXISTS seat_budget integer');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE autoscale_scopes DROP COLUMN IF EXISTS seat_budget');
    }
};
