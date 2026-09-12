<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        // Build one at a time with PostgreSQL's configured maintenance budget.
        // Concurrent builds leave the live organization table writable.
        foreach ([
            'organizations_directory_name_idx' => '(lower(name) COLLATE "C", id)',
            'organizations_directory_place_name_idx' => '(jurisdiction_id, lower(name) COLLATE "C", id)',
            'organizations_directory_type_name_idx' => '(type, lower(name) COLLATE "C", id)',
            'organizations_directory_structure_name_idx' => '(structure, lower(name) COLLATE "C", id)',
            'organizations_directory_type_structure_name_idx' => '(type, structure, lower(name) COLLATE "C", id)',
        ] as $name => $columns) {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON organizations '.$columns
                ." WHERE deleted_at IS NULL AND status <> 'dissolved'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS organizations_directory_type_structure_name_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS organizations_directory_structure_name_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS organizations_directory_type_name_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS organizations_directory_place_name_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS organizations_directory_name_idx');
    }
};
