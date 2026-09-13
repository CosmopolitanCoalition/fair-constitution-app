<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const TYPE = "(CASE endorser_type WHEN 'users' THEN 'user' WHEN 'organizations' THEN 'organization' ELSE endorser_type END)";

    private const INDEXES = [
        'profile_standing_frozen_date_idx' => 'approval_standings (race_id, as_of_date DESC) WHERE is_frozen = true',
        'profile_standing_rank_idx' => 'approval_standings (race_id, as_of_date, rank) INCLUDE (approvals_count)',
        'profile_received_endorsement_idx' => 'endorsements (candidate_id, '.self::TYPE.', is_public, id DESC) INCLUDE (election_id, endorser_id, endorser_type) WHERE is_active = true AND withdrawn_at IS NULL',
        'profile_given_endorsement_idx' => 'endorsements (endorser_id, '.self::TYPE.', id DESC) INCLUDE (election_id, candidate_id, endorser_type) WHERE is_active = true AND withdrawn_at IS NULL AND is_public = true',
        'profile_endorsement_web_idx' => 'endorsements (endorser_id, '.self::TYPE.', election_id, id DESC) INCLUDE (candidate_id, endorser_type) WHERE is_active = true AND withdrawn_at IS NULL AND is_public = true',
        'profile_endorsement_request_idx' => 'endorsement_requests (candidacy_id, id DESC)',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) {
                DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
