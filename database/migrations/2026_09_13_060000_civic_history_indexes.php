<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;
    private const INDEXES = [
        'setting_changes_history_idx' => 'setting_changes (jurisdiction_id, id DESC)',
        'case_filings_advocate_history_idx' => 'case_filings (advocate_id, seq DESC)',
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
