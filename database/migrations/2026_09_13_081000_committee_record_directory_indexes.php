<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'bills_committee_history_idx' => "bills (committee_id, (COALESCE(created_at, '1970-01-01 00:00:00+00'::timestamptz)) DESC, id DESC) WHERE deleted_at IS NULL",
        'committee_reports_history_idx' => "committee_reports (committee_id, (COALESCE(created_at, '1970-01-01 00:00:00+00'::timestamptz)) DESC, id DESC)",
        'committee_reports_bill_history_idx' => "committee_reports (committee_id, bill_id, (COALESCE(created_at, '1970-01-01 00:00:00+00'::timestamptz)) DESC, id DESC)",
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
