<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'cases_advocate_title_directory_idx' => 'title',
        'cases_advocate_docket_directory_idx' => 'docket_no',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (self::INDEXES as $name => $column) {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON cases (advocate_id, lower(COALESCE('.$column.', \'\')) COLLATE "C", id) WHERE deleted_at IS NULL AND filed_via_form = \'F-ADV-001\'');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (array_keys(self::INDEXES) as $name) DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
    }
};
