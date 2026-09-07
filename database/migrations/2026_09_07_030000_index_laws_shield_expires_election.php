<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        // Seating certifies each chamber and, per item, looked up laws whose
        // shield expires with a given election. With no index that was a full
        // Seq Scan of the 4.4M-row laws table (cost ~159k), so 13 sim lanes
        // contended on BufferIo and seating crawled at ~1.3 items/s. A partial
        // index on the (nullable, usually null) column turns it into a ~2-cost
        // index scan; seating measured ~12x faster after (1.3 -> 15.4 items/s).
        // CONCURRENTLY + IF NOT EXISTS so it never locks the table and is safe
        // on a box where the index was already built by hand.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS laws_shield_expires_election_idx '
            .'ON laws (shield_expires_with_election_id) '
            .'WHERE shield_expires_with_election_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS laws_shield_expires_election_idx');
    }
};
