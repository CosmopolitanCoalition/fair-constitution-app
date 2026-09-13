<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach ([
            'marketplace_orders_listing_idx' => 'ON marketplace_orders (listing_id)',
            'marketplace_orders_pending_directory_idx' => "ON marketplace_orders (listing_id, id) WHERE status = 'placed'",
        ] as $name => $definition) {
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS marketplace_orders_pending_directory_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS marketplace_orders_listing_idx');
    }
};
