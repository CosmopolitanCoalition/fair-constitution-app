<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P.6 — add `map_accepted_at` to `instance_settings`.
 *
 * The new Jurisdiction Viewer's "Accept Map Data & Continue" button at
 * planet scope writes this timestamp. Its presence gates the next setup
 * wizard step (apportionment / districts) — until the operator has
 * explicitly accepted the imported jurisdictions, the wizard does not
 * advance past Step 2.
 *
 * `apportionment_completed_at` already exists from migration
 * 2026_04_25_000001 — referenced here for context, not added.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('instance_settings', 'map_accepted_at')) {
            DB::statement('ALTER TABLE instance_settings ADD COLUMN IF NOT EXISTS map_accepted_at timestamptz');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE instance_settings DROP COLUMN IF EXISTS map_accepted_at');
    }
};
