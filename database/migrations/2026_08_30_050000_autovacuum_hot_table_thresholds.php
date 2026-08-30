<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Autovacuum hot-table thresholds (audit row, operator order 2026-08-30;
 * outside review catch: this lived only in the reference box's database
 * via ALTER SYSTEM and would never transfer to a fresh deploy).
 *
 * A day-long district run writes these six tables continuously; at the
 * default 20% scale factor vacuum falls a run behind (measured live: the
 * 11-row lease table under 44,647 dead tuples, every claim walking the
 * corpses). 2% keeps pace. The instance-wide cost limit rides compose
 * (PG_AUTOVACUUM_COST_LIMIT, installer-derived from cores).
 *
 * Additive, real-dated, idempotent (SET is a plain overwrite).
 */
return new class extends Migration
{
    private const HOT_TABLES = [
        'autoscale_worker_leases',
        'autoscale_items',
        'autoscale_scopes',
        'legislature_districts',
        'legislature_district_maps',
        'legislature_district_jurisdictions',
    ];

    public function up(): void
    {
        foreach (self::HOT_TABLES as $t) {
            DB::statement("ALTER TABLE {$t} SET (autovacuum_vacuum_scale_factor = 0.02)");
        }
        // The tiny high-churn table also gets an absolute threshold so a
        // burst of lease churn cannot hide behind a small row count.
        DB::statement('ALTER TABLE autoscale_worker_leases SET (autovacuum_vacuum_threshold = 100)');
    }

    public function down(): void
    {
        foreach (self::HOT_TABLES as $t) {
            DB::statement("ALTER TABLE {$t} RESET (autovacuum_vacuum_scale_factor)");
        }
        DB::statement('ALTER TABLE autoscale_worker_leases RESET (autovacuum_vacuum_threshold)');
    }
};
