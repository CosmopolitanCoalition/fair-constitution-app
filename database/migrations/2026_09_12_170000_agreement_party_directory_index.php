<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Concurrent build with PostgreSQL's configured maintenance budget.
        // The expression and partial predicate match the consent-name search.
        $name = 'users_agreement_party_name_idx';
        $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);
        if ($index !== null && ! $index->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY '.$name);
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON users (lower(name) COLLATE "C", id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_agreement_party_name_idx');
        }
    }
};
