<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'profile_public_activity_seek_idx' => "audit_log (actor_user_id, seq DESC) WHERE rejected = false AND module IN ('elections', 'residency', 'legislature', 'judiciary', 'executive') AND event NOT LIKE '%ping%' AND event NOT LIKE '%travel%' AND event NOT LIKE '%relocat%'",
        'profile_publication_seek_idx' => 'public_records (actor_user_id, seq DESC)',
        'profile_office_history_idx' => 'terms (holder_user_id, starts_on DESC, id DESC) WHERE deleted_at IS NULL',
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
