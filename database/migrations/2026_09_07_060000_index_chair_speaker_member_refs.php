<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Role-holder checks ("is this user the chair / the speaker") join
        // legislature_members on committees.chair_member_id and
        // legislatures.speaker_id. Both columns were unindexed, so each check
        // Seq-Scanned. This dominated the TRAINING phase (arming seated holders
        // runs these role checks per holder): 0.3 -> 3.0 items/s after. Mirrors
        // the alternate_member_id index from the judiciary pass.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS committees_chair_member_id_idx '
            .'ON committees (chair_member_id) WHERE chair_member_id IS NOT NULL'
        );
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS legislatures_speaker_id_idx '
            .'ON legislatures (speaker_id) WHERE speaker_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS committees_chair_member_id_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legislatures_speaker_id_idx');
    }
};
