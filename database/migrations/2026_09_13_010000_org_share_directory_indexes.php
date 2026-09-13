<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'org_open_share_directory_idx' => 'ON org_ownership_stakes (organization_id, id DESC) WHERE ended_at IS NULL',
        'users_share_recipient_name_idx' => 'ON users (lower(COALESCE(NULLIF(trim(display_name), \'\'), name)) COLLATE "C", id) WHERE deleted_at IS NULL',
        'orgs_share_recipient_name_idx' => 'ON organizations (lower(name) COLLATE "C", id) WHERE deleted_at IS NULL',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) {
                DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
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
