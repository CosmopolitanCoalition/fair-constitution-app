<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // The per-nominee eligibility check joins legislature_members on
        // m.id = committees.alternate_member_id and filters m.user_id = ?. The
        // legislature_members indexes all LEAD with legislature_id, and
        // committees.alternate_member_id was unindexed, so the check ran a
        // parallel Seq Scan of legislature_members (cost ~34,800) per call. It
        // dominated the judiciary phase. Two partial indexes turn it into a
        // nested loop of index scans (cost ~5); judiciary measured 0.52 -> 2.24
        // items/s across this and the vote_casts tally index.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS legislature_members_user_id_idx '
            .'ON legislature_members (user_id) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS committees_alternate_member_id_idx '
            .'ON committees (alternate_member_id) WHERE alternate_member_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legislature_members_user_id_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS committees_alternate_member_id_idx');
    }
};
