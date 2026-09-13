<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'ledger_currency_seek_idx' => ['ledger_entries', 'currency_id, seq'],
        'ledger_public_account_seek_idx' => ['ledger_entries', 'account_type, account_id, currency_id, seq'],
        'issuance_history_seek_idx' => ['issuance_events', 'currency_id, created_at, id'],
        'public_budget_seek_idx' => ['budgets', 'jurisdiction_id, currency_id, id', 'deleted_at IS NULL'],
        'public_borrowing_seek_idx' => ['borrowings', 'jurisdiction_id, currency_id, id'],
        'public_revenue_seek_idx' => ['revenue_streams', 'jurisdiction_id, currency_id, id', 'deleted_at IS NULL'],
        'budget_lines_seek_idx' => ['budget_lines', 'budget_id, id'],
        'levies_seek_idx' => ['levies', 'revenue_stream_id, id'],
        'finance_places_seek_idx' => ['jurisdictions', 'parent_id, id', 'deleted_at IS NULL'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (self::INDEXES as $name => [$table, $columns]) {
            $state = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if ($state !== null && ! $state->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            $where = isset(self::INDEXES[$name][2]) ? ' WHERE '.self::INDEXES[$name][2] : '';
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$table.' ('.$columns.')'.$where);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (array_keys(self::INDEXES) as $name) DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
    }
};
