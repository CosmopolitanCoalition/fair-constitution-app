<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'market_transactions_from_history_idx' => 'market_transactions (from_account_id, created_at DESC, id DESC)',
        'market_transactions_to_history_idx' => 'market_transactions (to_account_id, created_at DESC, id DESC)',
        'tax_filings_account_history_idx' => 'tax_filings (account_id, id DESC)',
        'org_conversions_history_idx' => 'org_conversions (organization_id, id DESC) WHERE deleted_at IS NULL',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (array_keys(self::INDEXES) as $name) DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
    }
};
