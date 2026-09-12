<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'marketplace_listings_directory_idx' => "ON marketplace_listings (status, created_at DESC, id DESC) WHERE deleted_at IS NULL",
        'share_offers_directory_idx' => "ON share_offers (status, created_at DESC, id DESC) WHERE deleted_at IS NULL",
        'resident_agreement_signers_by_person_idx' => 'ON resident_agreement_signers (signer_user_id, agreement_id)',
        'org_contracts_signed_by_person_idx' => 'ON org_contracts (signed_by_org_user_id, id) WHERE deleted_at IS NULL AND signed_by_org_user_id IS NOT NULL',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One concurrent build at a time, using the host's configured
        // maintenance_work_mem. No world rows or constitutional rules change.
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) {
                DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
