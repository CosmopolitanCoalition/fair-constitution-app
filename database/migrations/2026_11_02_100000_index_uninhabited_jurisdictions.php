<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UNINHABITED PLACES COUNT (operator order 2026-11-01).
 *
 * InstitutionProvisionService::skippedUninhabited() counts live jurisdictions with no population
 * and no live children that hold a legislature. The planner read the whole jurisdictions table
 * (2.4 GB, 110 seconds on the demo's spinning disk) to find the zero-population rows.
 *
 * A partial index holds only those rows, so the scan reads the few pages that match.
 * CONCURRENTLY: no write lock on `jurisdictions`. IF NOT EXISTS makes it a no-op when built by hand.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS jurisdictions_uninhabited_idx ON jurisdictions (id) WHERE deleted_at IS NULL AND COALESCE(population, 0) < 1');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS jurisdictions_uninhabited_idx');
    }
};
