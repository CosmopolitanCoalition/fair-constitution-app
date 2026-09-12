<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'terms_place_directory_idx' => "ON terms (jurisdiction_id, term_class, id DESC) WHERE status = 'active' AND deleted_at IS NULL",
        'terms_legislature_directory_idx' => "ON terms (jurisdiction_id, term_class, legislature_id, id DESC) WHERE status = 'active' AND deleted_at IS NULL",
        'audit_term_refusals_place_idx' => "ON audit_log (jurisdiction_id, seq DESC) WHERE rejected = true AND ref IN ('CLK-01', 'CLK-09', 'CLK-10')",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
