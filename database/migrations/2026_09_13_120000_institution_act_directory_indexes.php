<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'institution_acts_legislature_seek_idx' => 'chamber_vote_proposals (legislature_id, id DESC)',
        'institution_process_initiator_seek_idx' => 'multi_jurisdiction_votes (initiating_legislature_id, id DESC) WHERE deleted_at IS NULL',
        'constituent_consent_place_process_idx' => 'constituent_consents (jurisdiction_id, process_id)',
        'constituent_consent_process_seek_idx' => 'constituent_consents (process_id, id DESC)',
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
