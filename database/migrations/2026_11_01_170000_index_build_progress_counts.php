<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE BUILD PROGRESS COUNTS (operator order 2026-11-01, the page profile).
 *
 * "District maps adopted" joins the active maps to the chambers with more than 9 seats. On the
 * demo that is 590,000 matching rows (513,000 of 923,000 chambers are that large). With the
 * existing indexes Postgres walked two 1-million-row indexes and fetched a table row for every
 * match to test deleted_at and type_a_seats: about 1.5 million random reads, more than 21
 * minutes on a spinning disk and not finished. A partial index that already encodes those tests
 * lets the same join run as two sequential index-only scans. The vacuum sets the visibility map
 * that an index-only scan needs.
 *
 * CONCURRENTLY: no write lock. Neither statement can run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS legislature_district_maps_active_live_idx ON legislature_district_maps (legislature_id) WHERE status = 'active' AND deleted_at IS NULL");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS legislatures_big_chamber_live_idx ON legislatures (id) WHERE deleted_at IS NULL AND type_a_seats > 9');
        DB::statement('VACUUM (ANALYZE) legislature_district_maps');
        DB::statement('VACUUM (ANALYZE) legislatures');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legislature_district_maps_active_live_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legislatures_big_chamber_live_idx');
    }
};
