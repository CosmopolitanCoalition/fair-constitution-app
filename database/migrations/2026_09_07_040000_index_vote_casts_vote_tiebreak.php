<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Every chamber vote tally runs `count(*) from vote_casts where vote_id
        // = ? and is_tiebreak = ?`. The existing vote_casts indexes are all
        // PARTIAL (member_id / board_seat_id / is_tiebreak only), so a plain
        // tally had no usable index and did a parallel Seq Scan of the whole
        // table (cost ~7,000). This bit judiciary hardest (per-seat consent
        // votes) but also governance and seating. A (vote_id, is_tiebreak)
        // index turns it into an Index Only Scan (cost ~12); judiciary measured
        // 0.52 -> 1.72 items/s after.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS vote_casts_vote_id_tiebreak_idx '
            .'ON vote_casts (vote_id, is_tiebreak)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS vote_casts_vote_id_tiebreak_idx');
    }
};
