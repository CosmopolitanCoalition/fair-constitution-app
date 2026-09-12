<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'assets_owner_name_directory_idx' => 'ON assets (owner_account_id, lower(name) COLLATE "C", id) WHERE deleted_at IS NULL',
        'marketplace_listings_open_asset_idx' => "ON marketplace_listings (asset_id) WHERE status = 'open' AND asset_id IS NOT NULL",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Build sequentially with the host's configured maintenance budget.
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
