<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * jurisdictions had no plain btree index on iso_code — every same-iso join
 * in the geoboundaries orphan-resolution ladder (import_geoboundaries.py,
 * _resolve_orphans_at_level_via_strategy) filters on it, and at planet
 * scale (~950k rows) that meant a sequential-scan-backed filter on the
 * candidate-parent side of every strategy query. Additive, real-dated,
 * CONCURRENTLY so it never blocks a live run (operator-caught resolve
 * stall, 2026-08-02).
 */
return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction block —
    // Laravel wraps migrations in one by default.
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS jurisdictions_iso_code_index ON jurisdictions (iso_code)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS jurisdictions_iso_code_index');
    }
};
