<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `election_board_members.election_board_id` had no plain index.
 *
 * The only two indexes touching it were PARTIAL —
 *   election_board_members_one_seat  … WHERE status = 'seated' AND user_id IS NOT NULL
 *   election_board_members_system_uq … WHERE user_id IS NULL   (added 2026_07_25_000002)
 * — so neither can serve a general join or a plain "members of this board"
 * lookup. Every such query degrades to a sequential scan.
 *
 * Found the hard way: a three-way join deleting 50,000 boards' members ran
 * CPU-bound for over six minutes on a dev box during lane 3's provisioning
 * benchmark. At planet scale (955,130 boards) the same shape is unusable, and
 * it is a shape the app performs routinely — the board roster is on the
 * election-board surface.
 *
 * Foreign keys are not indexed automatically in PostgreSQL; this is the
 * ordinary companion index every other FK in this schema already has
 * (cf. election_boards_legislature_id_index).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS election_board_members_board_id_index
                ON election_board_members (election_board_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS election_board_members_board_id_index');
    }
};
