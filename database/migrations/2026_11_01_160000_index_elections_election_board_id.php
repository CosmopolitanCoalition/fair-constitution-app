<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE MISSING ELECTION BOARD INDEX (operator order 2026-11-01, the page profile).
 *
 * elections.election_board_id had no index. The board console looks up "this board's elections"
 * once per board row, and on 1.9 million rows each lookup read the whole table: 10 seconds a
 * page on the demo's spinning disk. A partial index on the live rows serves it.
 *
 * CONCURRENTLY: no write lock. That statement cannot run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS elections_election_board_id_idx ON elections (election_board_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS elections_election_board_id_idx');
    }
};
