<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * W-0440 — a prefix search over jurisdiction names, bounded.
 *
 * jurisdictions carries no index on name. A typed legislature search on the
 * public-records page (lower(name) LIKE 'prefix%', LIMIT 20) walked all
 * 951,626 rows in 3 to 6 s on box E (measured 2026-09-14). A btree index on
 * lower(name) with text_pattern_ops answers a prefix in milliseconds.
 * PostgreSQL only; additive; rerunnable (IF NOT EXISTS). SQLite fixtures
 * take no index (their tables are tiny and LIKE needs none).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS jurisdictions_lower_name_pattern_idx ON jurisdictions (lower(name) text_pattern_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS jurisdictions_lower_name_pattern_idx');
    }
};
