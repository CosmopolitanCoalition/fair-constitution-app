<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        $name = 'ubi_receipts_account_directory_idx';
        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
        if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON ubi_receipts (account_id, id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ubi_receipts_account_directory_idx');
    }
};
