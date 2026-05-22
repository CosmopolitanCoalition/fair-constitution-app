<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Setup wizard now runs apportionment synchronously inside activateStep1
 * (Step 2 → Step 3 transition) and ends with a Step 4 confirm + seat-
 * institutions screen. Track each milestone separately so the UI can
 * surface "apportionment ran at X / districts confirmed at Y" without
 * inferring from setup_step_completed alone, and so a re-run of setup
 * can detect what's already been done.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE instance_settings ADD COLUMN IF NOT EXISTS apportionment_completed_at timestamptz');
        DB::statement('ALTER TABLE instance_settings ADD COLUMN IF NOT EXISTS apportionment_log text');
        DB::statement('ALTER TABLE instance_settings ADD COLUMN IF NOT EXISTS setup_districts_confirmed_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE instance_settings DROP COLUMN IF EXISTS setup_districts_confirmed_at');
        DB::statement('ALTER TABLE instance_settings DROP COLUMN IF EXISTS apportionment_log');
        DB::statement('ALTER TABLE instance_settings DROP COLUMN IF EXISTS apportionment_completed_at');
    }
};
